<?php
/**
 * 既に公開中のイベントをまとめて送る操作のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Connection;
use EventWatch\Connection_Admin;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Settings;
use EventWatch\Site_Identity;
use EventWatch\Sync_Queue;
use RuntimeException;
use WP_UnitTestCase;
use WPDieException;

/**
 * 対象の選び方・操作・流量を確かめる。HTTP は pre_http_request で返す。
 */
class Bulk_Send_Test extends WP_UnitTestCase {

	const KEY = 'ewk_0123456789012345678901234567890123456789012';

	/**
	 * 受けた要求の URL。
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * キーを入れずに始める。キーの無い間に公開した投稿を作れるようにするため。
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		Event_Meta::register();
		Post_Type::register();
		delete_option( Connection::KEY_OPTION );
		Connection::forget();
		Site_Identity::use_as_moved();
		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
		$this->requests                = array();
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 前のテストの通知を数えない。
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * 差し替えを戻す。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		remove_all_filters( 'wp_redirect' );
		unset( $_REQUEST['_wpnonce'] );
		delete_transient( 'settings_errors' );
		wp_set_current_user( 0 );
		Sync_Queue::forget_all();
		delete_option( Sync_Queue::LOCK_OPTION );
		parent::tear_down();
	}

	/**
	 * 要求を記録して、審査待ちの応答を返す。
	 *
	 * @param mixed  $preempt 本体の値。
	 * @param array  $args    要求。
	 * @param string $url     URL。
	 * @return array
	 */
	public function answer( $preempt, $args, $url ) {
		$this->requests[] = $args['method'] . ' ' . $url;

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'applied' => true,
					'event'   => array(
						'status'              => 'pendingReview',
						'visible'             => false,
						'suspendedByOperator' => false,
						'rejectionReason'     => null,
						'slug'                => 'abc',
					),
				)
			),
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 公開中のイベントを作る。既定はこれから開催する、送れる内容のもの。
	 *
	 * @param string $start 開始日時。空ならこの先の日時。
	 * @param array  $post  上書きする投稿の値。
	 * @return int
	 */
	private function published_event( $start = '', array $post = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => '題',
					'post_content' => '<p>本文</p>',
				),
				$post
			)
		);
		update_post_meta(
			$id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => '' !== $start ? $start : wp_date( 'Y-m-d H:i', time() + 30 * DAY_IN_SECONDS ),
					'end'   => '',
				),
			)
		);
		update_post_meta( $id, Event_Meta::CATEGORY, 3 );
		update_post_meta( $id, Event_Meta::PREFECTURE, 13 );

		return $id;
	}

	/**
	 * キーを入れる。
	 *
	 * @return void
	 */
	private function enter_key() {
		update_option( Connection::KEY_OPTION, self::KEY );
		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
	}

	/**
	 * 管理者になる。
	 *
	 * @return void
	 */
	private function log_in_as_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
	}

	/**
	 * 操作を実行し、最後のリダイレクトで止める。
	 *
	 * @return void
	 */
	private function press() {
		$_REQUEST['_wpnonce'] = wp_create_nonce( Connection_Admin::ACTION_SEND_ALL );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- テストの中で読むだけ。
			}
		);

		try {
			Connection_Admin::handle_send_all();
		} catch ( RuntimeException $redirect ) {
			$this->assertStringContainsString( 'page=' . Settings::PAGE, $redirect->getMessage() );
			return;
		}

		$this->fail( 'The action did not redirect.' );
	}

	/**
	 * 渡された通知を読む。
	 *
	 * @return array
	 */
	private function reported() {
		$errors = get_transient( 'settings_errors' );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * 設定画面の行を描く。
	 *
	 * @return string
	 */
	private function field() {
		ob_start();
		Settings::render_sync_status_field();

		return (string) ob_get_clean();
	}

	/**
	 * 公開中で未送信の、これから開催するイベントだけが対象になる。
	 */
	public function test_selects_only_unsent_published_upcoming_events() {
		$target = $this->published_event();

		$this->published_event( '', array( 'post_status' => 'draft' ) );
		$this->published_event( '', array( 'post_password' => 'secret' ) );
		$this->published_event( '2020-01-01 10:00' );
		update_post_meta( $this->published_event(), Sync_Queue::NO_SEND, 1 );
		update_post_meta( $this->published_event(), Sync_Queue::EXISTS, 1 );
		update_post_meta( $this->published_event(), Sync_Queue::PENDING, 'token' );
		update_post_meta( $this->published_event(), Sync_Queue::IMPORT_ORIGINAL, 'hash' );
		delete_post_meta( $this->published_event(), Event_Meta::CATEGORY );

		$this->assertSame( array( $target ), Sync_Queue::unsent_published_ids() );
	}

	/**
	 * 今日に終わるイベントはまだ対象。開催予定の一覧と同じ境目で分ける。
	 */
	public function test_an_event_ending_today_is_included() {
		$target = $this->published_event( wp_date( 'Y-m-d' ) . ' 00:00' );

		$this->assertSame( array( $target ), Sync_Queue::unsent_published_ids() );
	}

	/**
	 * キーの保存だけでは何も積まれず、何も送られない。
	 */
	public function test_saving_a_key_sends_nothing() {
		$id = $this->published_event();

		update_option( Connection::KEY_OPTION, self::KEY );
		Sync_Queue::run();

		$this->assertSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 押すと印が付き、この要求では送らず、次の cron で届く。
	 */
	public function test_press_marks_and_the_next_cron_sends() {
		$id = $this->published_event();
		$no = $this->published_event();
		update_post_meta( $no, Sync_Queue::NO_SEND, 1 );
		$this->enter_key();
		$this->log_in_as_admin();

		$this->press();

		$this->assertNotSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', get_post_meta( $no, Sync_Queue::PENDING, true ) );
		$this->assertSame( array(), $this->requests );
		$this->assertGreaterThan( 0, wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
		$this->assertSame( 'success', $this->reported()[0]['type'] );

		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests, 'The request that marked events must not send them.' );

		Sync_Queue::run();

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'PUT ', $this->requests[0] );
		$this->assertStringEndsWith( '/events/' . $id, $this->requests[0] );
		$this->assertSame( 'pendingReview', get_post_meta( $id, Sync_Queue::REMOTE, true )['status'] );
		$this->assertSame( array(), Sync_Queue::unsent_published_ids() );
	}

	/**
	 * 100 件は 1 回の cron の上限を超えず、3 回で全部届く。
	 */
	public function test_100_events_stay_under_the_limit_and_all_arrive() {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->published_event();
		}
		$this->enter_key();

		$this->assertSame( 100, Sync_Queue::mark_unsent_published() );

		$counts = array();
		for ( $run = 0; $run < 3; $run++ ) {
			$before = count( $this->requests );
			Sync_Queue::run();
			$counts[] = count( $this->requests ) - $before;
		}

		$this->assertSame( array( Sync_Queue::RUN_MAX, Sync_Queue::RUN_MAX, 20 ), $counts );
		$this->assertLessThan( 60, Sync_Queue::RUN_MAX * 60 / Sync_Queue::RUN_INTERVAL );
		$this->assertSame( 0, Sync_Queue::pending_count() );
	}

	/**
	 * 接続を直すまで送れない間は、押しても印を付けず、理由を伝える。
	 *
	 * @dataProvider blocked_states
	 *
	 * @param string $status 接続の状態。
	 */
	public function test_press_while_connection_is_broken_marks_nothing( $status ) {
		$id = $this->published_event();
		$this->enter_key();
		$this->log_in_as_admin();
		update_option(
			Connection::STATE_OPTION,
			array(
				'status' => $status,
				'key'    => Connection::fingerprint(),
			)
		);

		$this->assertStringContainsString( ' disabled=', $this->field() );

		$this->press();

		$this->assertSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( 'warning', $this->reported()[0]['type'] );
	}

	/**
	 * 送れない接続の状態。
	 *
	 * @return array
	 */
	public function blocked_states() {
		return array(
			array( Connection::UNAUTHORIZED ),
			array( Connection::SUSPENDED ),
			array( Connection::FORBIDDEN ),
		);
	}

	/**
	 * 届かないのは一時的なので押せる。
	 */
	public function test_unreachable_can_still_be_pressed() {
		$this->published_event();
		$this->enter_key();
		update_option(
			Connection::STATE_OPTION,
			array(
				'status' => Connection::UNREACHABLE,
				'key'    => Connection::fingerprint(),
			)
		);

		$this->assertTrue( Sync_Queue::can_send_unsent() );
		$this->assertStringNotContainsString( ' disabled=', $this->field() );
	}

	/**
	 * サイトの URL が変わった間は押しても印を付けない。
	 */
	public function test_press_after_the_url_changed_marks_nothing() {
		$id = $this->published_event();
		$this->enter_key();
		$this->log_in_as_admin();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://old.example.com' ) );

		$this->assertTrue( Site_Identity::has_changed() );
		$this->assertStringContainsString( ' disabled=', $this->field() );

		$this->press();

		$this->assertSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * キーを消した後の古いタブから押しても、積んだと伝えない。
	 */
	public function test_press_without_a_key_reports_a_warning() {
		$this->published_event();
		$this->log_in_as_admin();

		$this->press();

		$this->assertSame( 'warning', $this->reported()[0]['type'] );
		$this->assertFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 対象が無いのに押された（古いタブ）ときは、送るものが無いと伝える。
	 */
	public function test_press_with_no_targets() {
		$this->enter_key();
		$this->log_in_as_admin();

		$this->press();

		$this->assertSame( 'info', $this->reported()[0]['type'] );
		$this->assertFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 管理者でなければ断る。
	 */
	public function test_press_requires_the_capability() {
		$id = $this->published_event();
		$this->enter_key();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->press();
			$this->fail( 'The action should be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
		}
	}

	/**
	 * Nonce が無ければ断る。
	 */
	public function test_press_requires_the_nonce() {
		$id = $this->published_event();
		$this->enter_key();
		$this->log_in_as_admin();
		$_REQUEST['_wpnonce'] = 'wrong';

		try {
			Connection_Admin::handle_send_all();
			$this->fail( 'The action should be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', get_post_meta( $id, Sync_Queue::PENDING, true ) );
		}
	}

	/**
	 * 画面: キーが無い・対象が無いときは操作を出さない。あれば件数とボタンを出す。
	 */
	public function test_field_shows_the_action_only_when_it_applies() {
		$this->published_event();

		$this->assertStringNotContainsString( Connection_Admin::ACTION_SEND_ALL, $this->field(), 'No key' );

		$this->enter_key();
		$field = $this->field();
		$this->assertStringContainsString( 'form="' . Connection_Admin::ACTION_SEND_ALL . '"', $field );
		$this->assertStringContainsString( 'not sent yet: 1 event', $field );
		$this->assertStringNotContainsString( ' disabled=', $field );

		Sync_Queue::mark_unsent_published();

		$this->assertStringNotContainsString( Connection_Admin::ACTION_SEND_ALL, $this->field(), 'No targets' );
	}
}
