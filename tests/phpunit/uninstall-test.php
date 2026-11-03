<?php
/**
 * 削除のときの後始末のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Api_Client;
use EventWatch\Capabilities;
use EventWatch\Connection;
use EventWatch\Event_Detail;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Queue;
use WP_UnitTestCase;

/**
 * uninstall.php のテスト。
 */
class Uninstall_Test extends WP_UnitTestCase {

	/**
	 * 基底クラスの tear_down がメタの登録を全部消すので、テストごとに登録する。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
	}

	/**
	 * uninstall.php を流す。
	 *
	 * 本体の uninstall_plugin() と同じく、global を宣言していない関数の中で読み込む。
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'eventwatch/eventwatch.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * キーがあれば切り離しを短いタイムアウトで送り、予定と同期のメタを消す。
	 */
	public function test_sends_detach_and_clears_cron_and_sync_meta() {
		Site_Identity::ensure();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		$site_id = Site_Identity::id();
		$post_id = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		update_post_meta( $post_id, Sync_Queue::HASH, 'x' );
		update_post_meta( $post_id, Sync_Queue::NO_SEND, 1 );
		update_post_meta( $post_id, Event_Detail::LISTING_SLUG, 'abc' );
		wp_schedule_single_event( time() + 100, Sync_Queue::RUN_HOOK );
		wp_schedule_single_event( time() + 100, Sync_Queue::RESCAN_HOOK );
		$sent  = array();
		$watch = static function ( $pre, $args, $url ) use ( &$sent ) {
			$sent[] = array( $args['method'], $url, $args['timeout'] );
			return new \WP_Error( 'http_request_failed', 'EventWatch is down' );
		};
		add_filter( 'pre_http_request', $watch, 10, 3 );

		$this->run_uninstall();

		remove_filter( 'pre_http_request', $watch, 10 );

		$this->assertSame(
			array( array( 'DELETE', Api_Client::base_url() . '/api/v1.0/org-api/sites/' . $site_id, (float) Sync_Queue::IMMEDIATE_TIMEOUT ) ),
			array_map(
				static function ( $request ) {
					return array( $request[0], $request[1], (float) $request[2] );
				},
				$sent
			)
		);
		// 送れなくても消える。
		$this->assertSame( 'gone', get_option( Connection::KEY_OPTION, 'gone' ) );
		$this->assertFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Sync_Queue::RESCAN_HOOK ) );
		$this->assertSame( '', get_post_meta( $post_id, Sync_Queue::HASH, true ) );
		$this->assertSame( '1', get_post_meta( $post_id, Sync_Queue::NO_SEND, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Event_Detail::LISTING_SLUG, true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	/**
	 * キーが無ければ、複製と判定されていれば、切り離しを送らない。
	 */
	public function test_sends_no_detach_without_key_or_from_copy() {
		$sent  = 0;
		$watch = static function () use ( &$sent ) {
			++$sent;
			return new \WP_Error( 'http_request_failed', 'EventWatch is down' );
		};
		add_filter( 'pre_http_request', $watch );

		$this->run_uninstall();
		$this->assertSame( 0, $sent );

		Site_Identity::ensure();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		update_option( Site_Identity::URL_OPTION, 'https://other.example.com' );
		$this->run_uninstall();
		$this->assertSame( 0, $sent );

		remove_filter( 'pre_http_request', $watch );
	}

	/**
	 * option と transient が消え、接頭辞の違うものと投稿は残る。
	 */
	public function test_removes_own_data_and_keeps_the_rest() {
		update_option( 'eventwatch_settings', 'x' );
		set_transient( 'eventwatch_cache', 'x', HOUR_IN_SECONDS );
		update_option( 'eventwatchfoo_settings', 'keep' );
		update_option( 'unrelated_eventwatch_settings', 'keep' );
		$post_id = self::factory()->post->create();

		$this->run_uninstall();

		wp_cache_flush();
		$this->assertFalse( get_option( 'eventwatch_settings' ) );
		$this->assertFalse( get_transient( 'eventwatch_cache' ) );
		$this->assertFalse( get_option( '_transient_timeout_eventwatch_cache' ) );
		$this->assertSame( 'keep', get_option( 'eventwatchfoo_settings' ) );
		$this->assertSame( 'keep', get_option( 'unrelated_eventwatch_settings' ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * 通知を閉じた印を、すべてのユーザーから消す。ほかのメタは残す。
	 */
	public function test_removes_the_hidden_notice_mark() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'eventwatch_hide_no_key_notice', 1 );
		update_user_meta( $user_id, 'unrelated_meta', 'keep' );

		$this->run_uninstall();

		wp_cache_flush();
		$this->assertSame( '', get_user_meta( $user_id, 'eventwatch_hide_no_key_notice', true ) );
		$this->assertSame( 'keep', get_user_meta( $user_id, 'unrelated_meta', true ) );
	}

	/**
	 * 既定ではイベントの投稿とタームを残し、権限はすべての役割から外す。
	 */
	public function test_keeps_events_by_default_and_removes_capabilities() {
		Capabilities::grant();
		add_role( 'eventwatch_test_role', 'Test', array( 'edit_eventwatch_events' => true ) );
		$post_id = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		$term_id = self::factory()->term->create( array( 'taxonomy' => Post_Type::TAX_TAG ) );

		$this->run_uninstall();

		$this->assertNotNull( get_post( $post_id ) );
		$this->assertNotNull( get_term( $term_id, Post_Type::TAX_TAG ) );
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( Capabilities::PRIMITIVES as $capability ) {
				$this->assertFalse( $role->has_cap( $capability ), $role->name . ' ' . $capability );
			}
		}

		remove_role( 'eventwatch_test_role' );
	}

	/**
	 * 設定を選ぶと、全状態の投稿とタームを消し、ほかの投稿と画像は残す。設定そのものも消える。
	 */
	public function test_deletes_events_when_option_is_set() {
		update_option( 'eventwatch_delete_events_on_uninstall', '1' );

		$event_ids = array();
		foreach ( array( 'publish', 'draft', 'future', 'private', 'trash' ) as $status ) {
			$event_ids[] = self::factory()->post->create(
				array(
					'post_type'   => Post_Type::POST_TYPE,
					'post_status' => $status,
					'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '',
				)
			);
		}
		$term_id = self::factory()->term->create( array( 'taxonomy' => Post_Type::TAX_CATEGORY ) );
		wp_set_object_terms( $event_ids[0], array( $term_id ), Post_Type::TAX_CATEGORY );
		$other      = self::factory()->post->create();
		$attachment = self::factory()->attachment->create();

		$this->run_uninstall();

		foreach ( $event_ids as $event_id ) {
			$this->assertNull( get_post( $event_id ) );
		}
		$this->assertNull( get_term( $term_id, Post_Type::TAX_CATEGORY ) );
		$this->assertNotNull( get_post( $other ) );
		$this->assertNotNull( get_post( $attachment ) );
		wp_cache_flush();
		$this->assertFalse( get_option( 'eventwatch_delete_events_on_uninstall' ) );
	}

	/**
	 * マルチサイトでは、ほかのサイトの権限も外れ、投稿を消すかはサイトごとの設定に従う。
	 *
	 * @group ms-required
	 */
	public function test_handles_events_and_capabilities_per_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$deleting = self::factory()->blog->create();
		switch_to_blog( $deleting );
		Capabilities::grant();
		update_option( 'eventwatch_delete_events_on_uninstall', '1' );
		$deleted_post = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		restore_current_blog();

		$keeping = self::factory()->blog->create();
		switch_to_blog( $keeping );
		Capabilities::grant();
		$kept_post = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		restore_current_blog();

		$this->run_uninstall();

		wp_cache_flush();
		switch_to_blog( $deleting );
		$deleted_result = get_post( $deleted_post );
		$cap_deleting   = get_role( 'editor' )->has_cap( 'edit_eventwatch_events' );
		restore_current_blog();
		switch_to_blog( $keeping );
		$kept_result = get_post( $kept_post );
		$cap_keeping = get_role( 'editor' )->has_cap( 'edit_eventwatch_events' );
		restore_current_blog();

		$this->assertNull( $deleted_result );
		$this->assertNotNull( $kept_result );
		$this->assertFalse( $cap_deleting );
		$this->assertFalse( $cap_keeping );
	}

	/**
	 * マルチサイトでは、ほかのサイトの option とネットワークの option も消える。
	 *
	 * @group ms-required
	 */
	public function test_removes_data_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$other_site = self::factory()->blog->create();
		switch_to_blog( $other_site );
		update_option( 'eventwatch_settings', 'x' );
		restore_current_blog();
		update_site_option( 'eventwatch_network_settings', 'x' );
		set_site_transient( 'eventwatch_network_cache', 'x', HOUR_IN_SECONDS );

		$this->run_uninstall();

		wp_cache_flush();
		switch_to_blog( $other_site );
		$option = get_option( 'eventwatch_settings' );
		restore_current_blog();

		$this->assertFalse( $option );
		$this->assertFalse( get_site_option( 'eventwatch_network_settings' ) );
		$this->assertFalse( get_site_transient( 'eventwatch_network_cache' ) );
		$this->assertFalse( get_site_option( '_site_transient_timeout_eventwatch_network_cache' ) );
	}

	/**
	 * マルチネットワークでは、ほかのネットワークのサイトと option も消える。
	 *
	 * @group ms-required
	 */
	public function test_removes_data_on_every_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$other_network = self::factory()->network->create();
		$other_site    = self::factory()->blog->create( array( 'network_id' => $other_network ) );
		switch_to_blog( $other_site );
		update_option( 'eventwatch_settings', 'x' );
		restore_current_blog();
		update_network_option( $other_network, 'eventwatch_network_settings', 'x' );

		$this->run_uninstall();

		wp_cache_flush();
		switch_to_blog( $other_site );
		$option = get_option( 'eventwatch_settings' );
		restore_current_blog();

		$this->assertFalse( $option );
		$this->assertFalse( get_network_option( $other_network, 'eventwatch_network_settings' ) );
	}
}
