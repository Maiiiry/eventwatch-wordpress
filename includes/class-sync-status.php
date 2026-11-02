<?php
/**
 * 同期の健康状態。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定画面に出す値を読む。書くのは送信の処理。
 */
class Sync_Status {

	const LAST_SYNC_OPTION = 'eventwatch_last_sync_at';

	/**
	 * 最後に送信に成功した時刻。まだ無ければ 0。
	 *
	 * @return int
	 */
	public static function last_success() {
		return absint( get_option( self::LAST_SYNC_OPTION, 0 ) );
	}

	/**
	 * 送信待ちの件数。
	 *
	 * @return int
	 */
	public static function pending_count() {
		/**
		 * 送信待ちの件数。
		 *
		 * @param int $count 件数。
		 */
		return absint( apply_filters( 'eventwatch_sync_pending_count', 0 ) );
	}

	/**
	 * WP-Cron の自動の実行が止められているか。
	 *
	 * @return bool
	 */
	public static function is_wp_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
