<?php
/**
 * 状態の取り込みのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Admin_Columns;
use EventWatch\Capabilities;
use EventWatch\Connection;
use EventWatch\Event_Detail;
use EventWatch\Event_Meta;
use EventWatch\Plugin;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Pull;
use EventWatch\Sync_Queue;
use EventWatch\Sync_Rest;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Sync_Pull のテスト。
 */
class Sync_Pull_Test extends WP_UnitTestCase {

	/**
	 * 次の応答。投稿の ID で引く。無ければ既定の応答。
	 *
	 * @var array
	 */
	private $answers = array();

	/**
	 * 既定の応答。
	 *
	 * @var array|\WP_Error|null
	 */
	private $default = null;

	/**
	 * 受けた要求。
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * 要求を受けたときに動かす処理。
	 *
	 * @var callable|null
	 */
	private $during = null;

	/**
	 * テストごとに登録し直し、HTTP を差し替える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		Capabilities::grant();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Site_Identity::use_as_moved();
		$this->answers  = array();
		$this->default  = null;
		$this->requests = array();
		$this->during   = null;
		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );
	}

	/**
	 * 状態を戻す。
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Sync_Queue::forget_all();
		parent::tear_down();
	}

	/**
	 * HTTP の差し替え。
	 *
	 * @param mixed  $pre  前の値。
	 * @param array  $args 引数。
	 * @param string $url  URL。
	 * @return array|\WP_Error
	 */
	public function answer_request( $pre, $args, $url ) {
		preg_match( '#/events/(\d+)#', $url, $matches );
		$id               = isset( $matches[1] ) ? (int) $matches[1] : 0;
		$this->requests[] = array( $args['method'], $id, (float) $args['timeout'] );

		if ( $this->during ) {
			call_user_func( $this->during, $id );
		}

		if ( isset( $this->answers[ $id ] ) ) {
			return $this->answers[ $id ];
		}

		// 取り込みの応答は投稿そのもの、送信の応答は event の中。どちらにも使える既定にする。
		return $this->default ?? $this->http(
			200,
			array(
				'status' => 'pendingReview',
				'event'  => array( 'status' => 'pendingReview' ),
			)
		);
	}

	/**
	 * 応答を作る。
	 *
	 * @param int        $status  ステータス。
	 * @param array|null $body    本文。
	 * @param array      $headers ヘッダー。
	 * @return array
	 */
	private function http( $status, $body = null, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 掲載中の応答。
	 *
	 * @return array
	 */
	private function listed() {
		return array(
			'status'  => 'published',
			'visible' => true,
			'slug'    => 'abc123',
		);
	}

	/**
	 * 送ってある公開中のイベント。60 秒の間隔の外にして、記録した要求を空にする。
	 *
	 * @return int
	 */
	private function sent_event() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => '題',
			)
		);
		update_post_meta(
			$id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => '2026-12-01 10:00',
					'end'   => '',
				),
			)
		);
		update_post_meta( $id, Event_Meta::CATEGORY, 3 );
		update_post_meta( $id, Event_Meta::PREFECTURE, 13 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		update_post_meta( $id, Sync_Queue::SENT_AT, time() - 600 );
		$this->requests = array();

		return $id;
	}

	/**
	 * 取り込むと状態が変わる。
	 *
	 * @return array[]
	 */
	public function pulled_states() {
		$listed = array(
			'status'  => 'published',
			'visible' => true,
			'slug'    => 'abc123',
		);

		return array(
			'承認'      => array( $listed, 'live', 'abc123' ),
			'差し戻し'    => array(
				array(
					'status'          => 'rejected',
					'rejectionReason' => '時刻を直してください',
					'slug'            => 'abc123',
				),
				'fix',
				'',
			),
			'運営の停止'   => array(
				array(
					'status'              => 'published',
					'visible'             => false,
					'suspendedByOperator' => true,
					'slug'                => 'abc123',
				),
				'stopped',
				'',
			),
			'出ていない公開' => array(
				array(
					'status'  => 'published',
					'visible' => false,
					'slug'    => 'abc123',
				),
				'stopped',
				'',
			),
			'審査待ちのまま' => array(
				array(
					'status' => 'pendingReview',
					'slug'   => 'abc123',
				),
				'review',
				'',
			),
		);
	}

	/**
	 * 状態とスラッグが、取り込みで書かれる。掲載中の判定は visible で見る。
	 *
	 * @dataProvider pulled_states
	 *
	 * @param array  $event 応答の投稿。
	 * @param string $state 状態。
	 * @param string $slug  スラッグのメタ。
	 */
	public function test_pull_writes_state_and_listing_slug( $event, $state, $slug ) {
		$id = $this->sent_event();
		update_post_meta( $id, Event_Detail::LISTING_SLUG, 'old' );
		$this->default = $this->http( 200, $event );

		$this->assertSame( Sync_Pull::OUTCOME_DONE, Sync_Pull::pull( $id, 5 ) );

		$this->assertSame( array( array( 'GET', $id, 5.0 ) ), $this->requests );
		$this->assertSame( $state, Sync_Rest::status( $id )['state'] );
		$this->assertSame( $slug, get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $id, Sync_Queue::PULLED_AT, true ) );
	}

	/**
	 * 掲載中のスラッグが無ければメタを書かない。「気になる」が壊れたリンクにならない。
	 */
	public function test_visible_without_slug_has_no_listing() {
		$id            = $this->sent_event();
		$this->default = $this->http(
			200,
			array(
				'status'  => 'published',
				'visible' => true,
			)
		);

		Sync_Pull::pull( $id, 5 );

		$this->assertSame( '', get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
	}

	/**
	 * 送信の応答でもスラッグが書かれ、掲載でなくなれば消える。
	 */
	public function test_send_response_writes_listing_slug() {
		$this->default = $this->http( 200, array( 'event' => $this->listed() ) );
		$id            = $this->sent_event();
		$this->assertSame( 'abc123', get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
		$this->assertNotFalse( wp_next_scheduled( Sync_Pull::RUN_HOOK ) );

		$this->default = $this->http(
			200,
			array(
				'event' => array(
					'status' => 'private',
					'slug'   => 'abc123',
				),
			)
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( '', get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
	}

	/**
	 * 取り下げの 404 は、掲載のスラッグも一緒に消す。
	 */
	public function test_unpublish_not_found_forgets_listing() {
		$this->default = $this->http( 200, array( 'event' => $this->listed() ) );
		$id            = $this->sent_event();

		$this->default = $this->http( 404, array() );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( '', get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
		$this->assertSame( '', get_post_meta( $id, Sync_Queue::EXISTS, true ) );
	}

	/**
	 * 差し戻しを取り込んだ後は、内容を変えない更新でも送る。
	 */
	public function test_update_after_pulled_rejection_resubmits() {
		$id = $this->sent_event();
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests, '差し戻しを知る前は、同じ内容を送らない' );

		$this->default = $this->http(
			200,
			array(
				'status'          => 'rejected',
				'rejectionReason' => '直してください',
			)
		);
		Sync_Pull::pull( $id, 5 );
		$this->requests = array();
		$this->default  = $this->http( 200, array( 'event' => array( 'status' => 'pendingReview' ) ) );

		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( 'PUT', $this->requests[0][0] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'review', Sync_Rest::status( $id )['state'] );
	}

	/**
	 * 404 は無いか削除済み。次の公開で作り直しに行く。
	 */
	public function test_not_found_forgets_the_remote() {
		$this->default = $this->http( 200, array( 'event' => $this->listed() ) );
		$id            = $this->sent_event();

		$this->default = $this->http( 404, array() );
		Sync_Pull::pull( $id, 5 );

		foreach ( array( Sync_Queue::EXISTS, Sync_Queue::HASH, Sync_Queue::REMOTE, Event_Detail::LISTING_SLUG ) as $key ) {
			$this->assertSame( '', get_post_meta( $id, $key, true ), $key );
		}
		$this->assertSame( 'unsent', Sync_Rest::status( $id )['state'] );

		$this->requests = array();
		$this->default  = $this->http( 201, array( 'event' => array( 'status' => 'pendingReview' ) ) );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( 'PUT', $this->requests[0][0] );
	}

	/**
	 * 一時的な失敗は手元を変えない。Retry-After があれば待ちを立てて、定期の回を止める。
	 *
	 * @return array[]
	 */
	public function stopping_failures() {
		return array(
			'429'          => array( 429, array( 'retry-after' => '120' ), 120 ),
			'503 の長い待ち'    => array( 503, array( 'retry-after' => '10' ), 10 ),
			'500 で待ちの指定なし' => array( 500, array(), 0 ),
			'401'          => array( 401, array(), 0 ),
			'403'          => array( 403, array(), 0 ),
		);
	}

	/**
	 * 失敗で定期の回が止まり、手元の状態は変わらない。
	 *
	 * @dataProvider stopping_failures
	 *
	 * @param int   $status  ステータス。
	 * @param array $headers ヘッダー。
	 * @param int   $hold    待ちの秒数。
	 */
	public function test_failure_stops_the_run_and_keeps_state( $status, $headers, $hold ) {
		$this->default = $this->http( 200, array( 'event' => $this->listed() ) );
		$first         = $this->sent_event();
		$this->sent_event();
		$before = get_post_meta( $first, Sync_Queue::REMOTE, true );

		$this->requests = array();
		$this->default  = $this->http( $status, array(), $headers );
		Sync_Pull::run();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( $before, get_post_meta( $first, Sync_Queue::REMOTE, true ) );
		$this->assertSame( 'abc123', get_post_meta( $first, Event_Detail::LISTING_SLUG, true ) );
		$this->assertSame( '', get_post_meta( $first, Sync_Queue::PULLED_AT, true ) );

		$until = (int) get_option( Sync_Queue::HOLD_OPTION, 0 );
		if ( $hold > 0 ) {
			$this->assertEqualsWithDelta( time() + $hold, $until, 2 );
		} else {
			$this->assertSame( 0, $until );
		}
	}

	/**
	 * 応答の投稿でない本文では、手元を変えず、順番は回す。
	 */
	public function test_body_without_event_keeps_state() {
		$id     = $this->sent_event();
		$before = get_post_meta( $id, Sync_Queue::REMOTE, true );

		$this->default = $this->http( 200, array( 'unexpected' => true ) );
		$this->assertSame( Sync_Pull::OUTCOME_DONE, Sync_Pull::pull( $id, 5 ) );

		$this->assertSame( $before, get_post_meta( $id, Sync_Queue::REMOTE, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $id, Sync_Queue::PULLED_AT, true ) );
	}

	/**
	 * 送り直しても変わらない断りは、回を止めず、次の投稿へ進む。
	 */
	public function test_other_rejection_continues_the_run() {
		$first = $this->sent_event();
		$next  = $this->sent_event();

		$this->answers[ $first ] = $this->http( 400, array() );
		$this->default           = $this->http( 200, $this->listed() );
		Sync_Pull::run();

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'live', Sync_Rest::status( $next )['state'] );
		$this->assertGreaterThan( 0, (int) get_post_meta( $first, Sync_Queue::PULLED_AT, true ) );
	}

	/**
	 * 読みに行かない条件。HTTP を出さない。
	 *
	 * @return array[]
	 */
	public function no_read_conditions() {
		return array(
			'キーなし' => array(
				static function () {
					delete_option( Connection::KEY_OPTION );
				},
			),
			'複製'   => array(
				static function () {
					update_option( Site_Identity::URL_OPTION, 'https://other.example' );
				},
			),
			'待ちの間' => array(
				static function () {
					update_option( Sync_Queue::HOLD_OPTION, time() + 100 );
				},
			),
		);
	}

	/**
	 * 定期でも、編集画面でも読みに行かない。
	 *
	 * @dataProvider no_read_conditions
	 *
	 * @param callable $arrange 条件を作る。
	 */
	public function test_no_read_when( $arrange ) {
		$id = $this->sent_event();
		$arrange();

		Sync_Pull::run();
		Sync_Pull::maybe_pull( $id );

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 待ちが明けていれば読む。
	 */
	public function test_expired_hold_allows_read() {
		$id = $this->sent_event();
		update_option( Sync_Queue::HOLD_OPTION, time() - 1 );

		Sync_Pull::maybe_pull( $id );

		$this->assertCount( 1, $this->requests );
	}

	/**
	 * 送っていない投稿・送信待ちの投稿は引かない。
	 */
	public function test_skips_unsent_and_queued() {
		$unsent = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$queued = $this->sent_event();
		update_post_meta( $queued, Sync_Queue::PENDING, 'token' );

		$this->assertSame( Sync_Pull::OUTCOME_SKIPPED, Sync_Pull::pull( $unsent, 5 ) );
		$this->assertSame( Sync_Pull::OUTCOME_SKIPPED, Sync_Pull::pull( $queued, 5 ) );
		Sync_Pull::run();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 引いている間に別の要求が送っていたら、応答を捨てる。同じ要求のメタの覚えでは見えない変更を読む。
	 *
	 * @return array[]
	 */
	public function concurrent_changes() {
		return array(
			'送信が終わった' => array( Sync_Queue::SENT_AT, '1' ),
			'印が積まれた'  => array( Sync_Queue::PENDING, 'token' ),
		);
	}

	/**
	 * 古い応答で送信の結果を上書きしない。応答を失った PUT の直後の 404 で記録を消さない。
	 *
	 * @dataProvider concurrent_changes
	 *
	 * @param string $key   変わるメタ。
	 * @param string $value 値。
	 */
	public function test_discards_response_when_sent_meanwhile( $key, $value ) {
		global $wpdb;

		$id     = $this->sent_event();
		$before = get_post_meta( $id, Sync_Queue::REMOTE, true );

		// 別の要求の書き込みなので、この要求のメタの覚えを更新しない。
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$this->during = static function () use ( $wpdb, $id, $key, $value ) {
			$wpdb->delete(
				$wpdb->postmeta,
				array(
					'post_id'  => $id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		};

		// phpcs:enable

		$this->default = $this->http( 404, array() );
		$this->assertSame( Sync_Pull::OUTCOME_SKIPPED, Sync_Pull::pull( $id, 5 ) );
		$this->assertSame( '1', get_post_meta( $id, Sync_Queue::EXISTS, true ) );

		$this->default = $this->http( 200, $this->listed() );
		delete_post_meta( $id, Sync_Queue::PENDING );
		update_post_meta( $id, Sync_Queue::SENT_AT, time() - 600 );
		Sync_Pull::pull( $id, 5 );
		$this->assertSame( $before, get_post_meta( $id, Sync_Queue::REMOTE, true ) );
	}

	/**
	 * 編集画面を開いたとき。60 秒以内の取り込みか送信があれば引かない。短いタイムアウトで引く。
	 */
	public function test_open_interval() {
		$id = $this->sent_event();

		update_post_meta( $id, Sync_Queue::SENT_AT, time() - Sync_Pull::OPEN_INTERVAL + 5 );
		Sync_Pull::maybe_pull( $id );
		$this->assertSame( array(), $this->requests, '送信の直後' );

		update_post_meta( $id, Sync_Queue::SENT_AT, time() - Sync_Pull::OPEN_INTERVAL - 1 );
		Sync_Pull::maybe_pull( $id );
		$this->assertSame( array( array( 'GET', $id, (float) Sync_Pull::OPEN_TIMEOUT ) ), $this->requests );

		$this->requests = array();
		Sync_Pull::maybe_pull( $id );
		$this->assertSame( array(), $this->requests, '取り込みの直後' );
	}

	/**
	 * 状態の口が取り込む。失敗しても手元の状態を返す。
	 */
	public function test_status_route_pulls_on_open() {
		$id = $this->sent_event();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->default = $this->http( 200, $this->listed() );

		$request  = new WP_REST_Request( 'GET', '/eventwatch/v1/events/' . $id . '/sync' );
		$response = rest_do_request( $request );

		$this->assertSame( 'live', $response->get_data()['state'] );
		$this->assertStringContainsString( '/events/abc123', $response->get_data()['link']['url'] );

		update_post_meta( $id, Sync_Queue::PULLED_AT, time() - 600 );
		$this->default = new \WP_Error( 'http_request_failed', 'down' );
		$response      = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'live', $response->get_data()['state'] );
	}

	/**
	 * 定期の順番。古い順で、上限までで、公開中だけ。送信で先頭へ戻る。
	 */
	public function test_run_order_and_limit() {
		$ids = array();
		for ( $i = 0; $i < Sync_Pull::RUN_MAX + 2; $i++ ) {
			$ids[] = $this->sent_event();
			update_post_meta( end( $ids ), Sync_Queue::PULLED_AT, 1000 + $i );
		}
		$never = $this->sent_event();
		delete_post_meta( $never, Sync_Queue::PULLED_AT );
		$draft = $this->sent_event();
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		delete_post_meta( $draft, Sync_Queue::PENDING );
		$this->requests = array();

		Sync_Pull::run();

		$pulled = array_column( $this->requests, 1 );
		$this->assertCount( Sync_Pull::RUN_MAX, $pulled );
		$this->assertSame( $never, $pulled[0] );
		$this->assertSame( array_slice( $ids, 0, Sync_Pull::RUN_MAX - 1 ), array_slice( $pulled, 1 ) );
		$this->assertNotContains( $draft, $pulled );
		$this->assertSame( (float) Sync_Pull::RUN_TIMEOUT, $this->requests[0][2] );
	}

	/**
	 * 送信すると、定期の順番の先頭へ戻る。
	 */
	public function test_sending_resets_pull_order() {
		$id = $this->sent_event();
		update_post_meta( $id, Sync_Queue::PULLED_AT, time() );

		update_post_meta( $id, Event_Meta::CATEGORY, 4 );
		Sync_Queue::mark( $id, false );
		Sync_Queue::send_marked();

		$this->assertSame( '', get_post_meta( $id, Sync_Queue::PULLED_AT, true ) );
	}

	/**
	 * Cron の登録。無効化で外れ、キーがあれば有効化で張り直す。キーなしには張らない。複製の選択で外れる。
	 */
	public function test_schedule_lifecycle() {
		$this->assertContains( Sync_Pull::RUN_HOOK, Plugin::CRON_HOOKS );
		$this->sent_event();
		$this->assertSame( 'hourly', wp_get_schedule( Sync_Pull::RUN_HOOK ) );

		Sync_Pull::schedule();
		$this->assertCount( 1, array_filter( array_keys( (array) _get_cron_array() ), array( $this, 'has_pull_hook' ) ) );

		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( Sync_Pull::RUN_HOOK ) );

		Plugin::activate();
		$this->assertNotFalse( wp_next_scheduled( Sync_Pull::RUN_HOOK ) );

		Sync_Queue::forget_all();
		$this->assertFalse( wp_next_scheduled( Sync_Pull::RUN_HOOK ) );

		delete_option( Connection::KEY_OPTION );
		Plugin::activate();
		$this->assertFalse( wp_next_scheduled( Sync_Pull::RUN_HOOK ) );
	}

	/**
	 * その時刻に取り込みの cron があるか。
	 *
	 * @param int $timestamp 時刻。
	 * @return bool
	 */
	public function has_pull_hook( $timestamp ) {
		$cron = _get_cron_array();

		return isset( $cron[ $timestamp ][ Sync_Pull::RUN_HOOK ] );
	}

	/**
	 * 複製の選択で、取り込みの記録とスラッグが消える。
	 */
	public function test_forget_all_clears_pull_records() {
		$this->default = $this->http( 200, $this->listed() );
		$id            = $this->sent_event();
		update_post_meta( $id, Sync_Queue::PULLED_AT, 1 );
		update_post_meta( $id, Event_Detail::LISTING_SLUG, 'abc' );

		Sync_Queue::forget_all();

		$this->assertSame( '', get_post_meta( $id, Sync_Queue::PULLED_AT, true ) );
		$this->assertSame( '', get_post_meta( $id, Event_Detail::LISTING_SLUG, true ) );
	}

	/**
	 * 未認証の REST に、取り込んだ値が出ない。
	 */
	public function test_pulled_values_are_not_public() {
		$id            = $this->sent_event();
		$this->default = $this->http(
			200,
			array(
				'status'          => 'rejected',
				'rejectionReason' => '非公開の理由',
				'slug'            => 'secret-slug',
			)
		);
		Sync_Pull::pull( $id, 5 );
		update_post_meta( $id, Event_Detail::LISTING_SLUG, 'secret-slug' );
		wp_set_current_user( 0 );

		$route    = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $id );
		$response = rest_do_request( $route );
		$json     = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '非公開の理由', (string) wp_json_encode( $response->get_data(), JSON_UNESCAPED_UNICODE ) );
		$this->assertStringNotContainsString( 'secret-slug', $json );
		$this->assertStringNotContainsString( '_eventwatch', $json );
		$this->assertStringNotContainsString( 'rejected', $json );

		$status = rest_do_request( new WP_REST_Request( 'GET', '/eventwatch/v1/events/' . $id . '/sync' ) );
		$this->assertSame( 401, $status->get_status() );
	}

	/**
	 * 状態ごとのリンク。掲載中はそのページ、止まっていれば問い合わせ先。ほかは無し。
	 */
	public function test_status_links() {
		$id = $this->sent_event();
		$this->assertSame( '', Sync_Rest::status( $id )['link']['url'] );

		update_post_meta( $id, Event_Detail::LISTING_SLUG, 'abc123' );
		update_post_meta( $id, Sync_Queue::REMOTE, array_merge( $this->remote_defaults(), $this->listed() ) );
		$this->assertStringEndsWith( '/events/abc123', Sync_Rest::status( $id )['link']['url'] );

		update_post_meta( $id, Sync_Queue::REMOTE, array_merge( $this->remote_defaults(), array( 'suspended' => true ) ) );
		$this->assertStringEndsWith( '/contact', Sync_Rest::status( $id )['link']['url'] );
	}

	/**
	 * 持っているメタの既定の形。
	 *
	 * @return array
	 */
	private function remote_defaults() {
		return array(
			'status'          => 'published',
			'visible'         => false,
			'suspended'       => false,
			'rejectionReason' => '',
			'slug'            => '',
		);
	}

	/**
	 * 断られた欄の表。
	 *
	 * @return array[]
	 */
	public function rejected_fields() {
		return array(
			'欄へ移れる'  => array( 'venueName', 'eventwatch-field-venue-name' ),
			'公式ページ'  => array( 'publisherEventPageUrl', 'eventwatch-field-url' ),
			'日程の補足'  => array( 'holdingPeriodNote', 'eventwatch-field-schedule-note' ),
			'住所'     => array( 'venueAddress', 'eventwatch-field-venue-address' ),
			'カテゴリ'   => array( 'category', 'eventwatch-field-category' ),
			'都道府県'   => array( 'prefectureCode', 'eventwatch-field-prefecture' ),
			'題は文だけ'  => array( 'title', '' ),
			'本文は文だけ' => array( 'description', '' ),
			'日程は文だけ' => array( 'occurrenceRanges', '' ),
		);
	}

	/**
	 * 400 の欄が状態に出る。次の更新で消える。
	 *
	 * @dataProvider rejected_fields
	 *
	 * @param string $name 欄の名前。
	 * @param string $id   画面の欄の ID。
	 */
	public function test_validation_error_names_the_field( $name, $id ) {
		$post_id       = $this->sent_event();
		$this->default = $this->http( 400, array( 'message' => "Field[$name]:Error[Size:size must be between]" ) );
		update_post_meta( $post_id, Event_Meta::CATEGORY, 4 );
		Sync_Queue::mark( $post_id, false );
		Sync_Queue::send_marked();

		$status = Sync_Rest::status( $post_id );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( array( $name ), array_column( $status['fieldErrors'], 'code' ) );
		$this->assertSame( $id, $status['fieldErrors'][0]['field'] );
		$this->assertNotSame( '', $status['fieldErrors'][0]['message'] );
		$this->assertSame( '' !== $id, '' !== $status['fieldErrors'][0]['action'] );
		$this->assertStringNotContainsString( 'size must be', (string) wp_json_encode( $status ) );

		$this->default = $this->http( 200, array( 'event' => array( 'status' => 'pendingReview' ) ) );
		Sync_Queue::mark( $post_id, false );
		$this->assertSame( array(), Sync_Rest::status( $post_id )['fieldErrors'] );
	}

	/**
	 * 知らない欄は出さず、汎用の理由に任せる。fields の無い古い記録でも読める。
	 */
	public function test_unknown_field_and_old_record() {
		$id            = $this->sent_event();
		$this->default = $this->http( 400, array( 'message' => 'Field[zoneId]:Error[x]' ) );
		update_post_meta( $id, Event_Meta::CATEGORY, 4 );
		Sync_Queue::mark( $id, false );
		Sync_Queue::send_marked();

		$status = Sync_Rest::status( $id );
		$this->assertSame( array(), $status['fieldErrors'] );
		$this->assertNotSame( '', $status['reason']['text'] );

		update_post_meta(
			$id,
			Sync_Queue::ERROR,
			array(
				'code'    => 'eventwatch_invalid_request',
				'apiCode' => '',
				'status'  => 400,
			)
		);
		$this->assertSame( array(), Sync_Rest::status( $id )['fieldErrors'] );
	}

	/**
	 * 一覧の列。語が出て、キーなしでは列が無い。
	 */
	public function test_list_column_is_only_with_a_key() {
		$columns = Admin_Columns::add_columns( array( 'title' => 'T' ) );
		$this->assertArrayHasKey( Admin_Columns::COLUMN_SYNC, $columns );

		delete_option( Connection::KEY_OPTION );
		$columns = Admin_Columns::add_columns( array( 'title' => 'T' ) );
		$this->assertArrayNotHasKey( Admin_Columns::COLUMN_SYNC, $columns );
	}

	/**
	 * 列の中身を取り出す。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private function cell( $post_id ) {
		ob_start();
		Admin_Columns::render_column( Admin_Columns::COLUMN_SYNC, $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * 一覧の列に状態と理由が出る。理由は escape して切り、編集できない人には出さない。
	 */
	public function test_list_column_shows_reason_to_editors_only() {
		$id   = $this->sent_event();
		$long = '<b>時刻</b>' . str_repeat( 'あ', 60 );
		update_post_meta(
			$id,
			Sync_Queue::REMOTE,
			array_merge(
				$this->remote_defaults(),
				array(
					'status'          => 'rejected',
					'rejectionReason' => $long,
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$cell = $this->cell( $id );
		$this->assertStringContainsString( 'eventwatch-sync-cell--warning', $cell );
		$this->assertStringContainsString( 'Changes requested', $cell );
		$this->assertStringContainsString( '&lt;b&gt;時刻', $cell );
		$this->assertStringNotContainsString( '<b>', $cell );
		$this->assertStringNotContainsString( str_repeat( 'あ', 60 ) . '</span>', $cell );
		$this->assertStringContainsString( '…', $cell );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$cell = $this->cell( $id );
		$this->assertStringContainsString( 'Changes requested', $cell );
		$this->assertStringNotContainsString( '時刻', $cell );
	}

	/**
	 * 列の見た目は一覧の画面にだけ足す。
	 */
	public function test_list_style_only_on_the_list_screen() {
		$inline = static function () {
			$after = wp_styles()->get_data( 'wp-admin', 'after' );
			wp_styles()->add_data( 'wp-admin', 'after', array() );
			return implode( '', (array) $after );
		};

		set_current_screen( 'edit-' . Post_Type::POST_TYPE );
		Admin_Columns::enqueue_style( 'post.php' );
		$this->assertStringNotContainsString( 'eventwatch-sync-cell', $inline() );

		Admin_Columns::enqueue_style( 'edit.php' );
		$this->assertStringContainsString( 'eventwatch-sync-cell', $inline() );

		set_current_screen( 'edit-post' );
		Admin_Columns::enqueue_style( 'edit.php' );
		$this->assertStringNotContainsString( 'eventwatch-sync-cell', $inline() );
		set_current_screen( 'front' );
	}
}
