<?php
/**
 * EventWatch での状態の取り込み。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 審査は送信の後に非同期で起きるので、状態を読みに行く。編集画面を開いたときと、定期の WP-Cron から呼ぶ。
 *
 * 一覧の口が無いので 1 件ずつ引く。読むだけで、投稿の内容は変えない。
 */
class Sync_Pull {

	const RUN_HOOK = 'eventwatch_sync_pull';

	/**
	 * 定期の 1 回。件数の多いサイトは、数回に分けて 1 周する。
	 */
	const RUN_MAX     = 30;
	const RUN_BUDGET  = 25;
	const RUN_TIMEOUT = 10;

	/**
	 * 編集画面を開いたとき。パネルの表示を長く待たせず、保存の後の読み直しで HTTP を増やさない。
	 */
	const OPEN_TIMEOUT  = 3;
	const OPEN_INTERVAL = 60;

	const OUTCOME_DONE    = 'done';
	const OUTCOME_SKIPPED = 'skipped';
	const OUTCOME_STOP    = 'stop';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::RUN_HOOK, array( self::class, 'run' ) );
	}

	/**
	 * 定期の実行を張る。送ったことのあるサイトだけが呼ぶ。
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::RUN_HOOK );
		}
	}

	/**
	 * 編集画面から呼ぶ。少し前に分かった状態があれば引かない。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function maybe_pull( $post_id ) {
		$last = max( (int) get_post_meta( $post_id, Sync_Queue::PULLED_AT, true ), (int) get_post_meta( $post_id, Sync_Queue::SENT_AT, true ) );

		if ( time() - $last >= self::OPEN_INTERVAL ) {
			self::pull( $post_id, self::OPEN_TIMEOUT );
		}
	}

	/**
	 * 定期の 1 回。取り込みの古い順に引く。
	 *
	 * @return void
	 */
	public static function run() {
		$start = time();
		foreach ( self::due_post_ids() as $post_id ) {
			if ( time() - $start >= self::RUN_BUDGET || self::OUTCOME_STOP === self::pull( $post_id, self::RUN_TIMEOUT ) ) {
				break;
			}
		}
	}

	/**
	 * 1 件の状態を引いて、手元に書く。
	 *
	 * @param int $post_id 投稿の ID。
	 * @param int $timeout タイムアウトの秒数。
	 * @return string
	 */
	public static function pull( $post_id, $timeout ) {
		if ( ! self::can_read() || ! get_post_meta( $post_id, Sync_Queue::EXISTS, true ) || get_post_meta( $post_id, Sync_Queue::PENDING, true ) ) {
			return self::OUTCOME_SKIPPED;
		}

		$sent_at = (string) get_post_meta( $post_id, Sync_Queue::SENT_AT, true );
		$result  = ( new Api_Client() )->request( 'GET', Sync_Queue::post_path( $post_id ), array( 'timeout' => $timeout ) );

		// 引いている間に別の要求が送っていたら、応答はそれより古い。覚えたメタでは見えないので読み直す。
		wp_cache_delete( $post_id, 'post_meta' );
		if ( get_post_meta( $post_id, Sync_Queue::PENDING, true ) || (string) get_post_meta( $post_id, Sync_Queue::SENT_AT, true ) !== $sent_at ) {
			return self::OUTCOME_SKIPPED;
		}

		if ( is_wp_error( $result ) ) {
			return self::handle_error( $post_id, $result );
		}

		if ( is_array( $result['body'] ) && isset( $result['body']['status'] ) ) {
			Sync_Queue::store_remote( $post_id, $result['body'] );
		}
		update_post_meta( $post_id, Sync_Queue::PULLED_AT, time() );

		return self::OUTCOME_DONE;
	}

	/**
	 * 失敗を扱う。手元の状態は変えない。
	 *
	 * @param int      $post_id 投稿の ID。
	 * @param WP_Error $error   失敗。
	 * @return string
	 */
	private static function handle_error( $post_id, WP_Error $error ) {
		if ( Api_Client::NOT_FOUND === $error->get_error_code() ) {
			Sync_Queue::forget_remote( $post_id );
			return self::OUTCOME_DONE;
		}

		if ( Api_Client::is_temporary( $error ) ) {
			$data = $error->get_error_data();
			if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
				Sync_Queue::hold_for( (int) $data['retry_after'] );
			}
			return self::OUTCOME_STOP;
		}

		if ( Sync_Queue::is_halting( $error ) ) {
			return self::OUTCOME_STOP;
		}

		// 送り直しても変わらない断り。ほかの投稿の順番を塞がない。
		update_post_meta( $post_id, Sync_Queue::PULLED_AT, time() );

		return self::OUTCOME_DONE;
	}

	/**
	 * いま読みに行ってよいか。URL が変わった複製に本番の状態を書くと、複製に本番の掲載が出る。
	 * キーなしはクライアントが HTTP を出さずに断り、その回が止まる。
	 *
	 * @return bool
	 */
	private static function can_read() {
		return ! Site_Identity::has_changed() && (int) get_option( Sync_Queue::HOLD_OPTION, 0 ) <= time();
	}

	/**
	 * 定期の対象。公開中で、送ってあって、送信待ちでない投稿。取り下げた投稿は運営の操作でしか動かない。
	 *
	 * @return int[]
	 */
	private static function due_post_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値の比較と並べ替えを 1 文で行う。結果を覚えると順番を読み違える。
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s WHERE p.post_type = %s AND p.post_status = 'publish' AND q.post_id IS NULL ORDER BY COALESCE( CAST( a.meta_value AS UNSIGNED ), 0 ), p.ID LIMIT %d",
				Sync_Queue::EXISTS,
				Sync_Queue::PENDING,
				Sync_Queue::PULLED_AT,
				Post_Type::POST_TYPE,
				self::RUN_MAX
			)
		);

		return array_map( 'intval', $post_ids );
	}
}
