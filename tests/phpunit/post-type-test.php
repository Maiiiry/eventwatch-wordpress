<?php
/**
 * 投稿タイプ・タクソノミー・権限のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Capabilities;
use EventWatch\Plugin;
use EventWatch\Post_Type;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Post_Type と Capabilities のテスト。
 */
class Post_Type_Test extends WP_UnitTestCase {

	/**
	 * テストのたびに権限を付ける。テストの環境では有効化が流れない。
	 */
	public function set_up() {
		parent::set_up();
		Capabilities::grant();
	}

	/**
	 * 無効化が登録を外すので、ほかのテストのために戻す。
	 */
	public function tear_down() {
		Post_Type::register();
		parent::tear_down();
	}

	/**
	 * 登録の中身が設計どおり。
	 */
	public function test_post_type_is_registered_as_designed() {
		$object = get_post_type_object( Post_Type::POST_TYPE );

		$this->assertTrue( $object->public );
		$this->assertTrue( $object->show_in_rest );
		$this->assertFalse( $object->has_archive );
		$this->assertSame( 'event', $object->rewrite['slug'] );
		$this->assertFalse( $object->rewrite['with_front'] );
		foreach ( array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields' ) as $feature ) {
			$this->assertTrue( post_type_supports( Post_Type::POST_TYPE, $feature ), $feature );
		}
	}

	/**
	 * タクソノミーは管理画面と REST に出て、公開の URL を持たない。
	 */
	public function test_taxonomies_have_no_public_urls() {
		foreach ( array( Post_Type::TAX_CATEGORY, Post_Type::TAX_TAG ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$this->assertFalse( $object->public, $taxonomy );
			$this->assertFalse( $object->publicly_queryable, $taxonomy );
			$this->assertTrue( $object->show_ui, $taxonomy );
			$this->assertTrue( $object->show_in_rest, $taxonomy );
			$this->assertFalse( $object->rewrite, $taxonomy );
		}
		$this->assertTrue( get_taxonomy( Post_Type::TAX_CATEGORY )->hierarchical );
		$this->assertFalse( get_taxonomy( Post_Type::TAX_TAG )->hierarchical );
	}

	/**
	 * 管理者と編集者は作成・公開でき、ほかの役割はできない。
	 *
	 * @dataProvider data_roles
	 *
	 * @param string $role    役割。
	 * @param bool   $allowed 作成できるか。
	 */
	public function test_only_admins_and_editors_can_create( $role, $allowed ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::POST_TYPE );
		$request->set_body_params(
			array(
				'title'  => 'Event',
				'status' => 'draft',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( $allowed ? 201 : 403, $response->get_status() );
		$this->assertSame( $allowed, current_user_can( 'publish_eventwatch_events' ) );
	}

	/**
	 * 役割の一覧。
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function data_roles() {
		return array(
			'editor'      => array( 'editor', true ),
			'author'      => array( 'author', false ),
			'contributor' => array( 'contributor', false ),
			'subscriber'  => array( 'subscriber', false ),
		);
	}

	/**
	 * 権限の無いユーザーは、既存のイベントを編集できず、メタも書けない。
	 */
	public function test_user_without_capability_cannot_edit_existing_event() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $post_id );
		$request->set_body_params( array( 'meta' => array( 'eventwatch_venue_name' => 'Hall' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * 未ログインでは、下書きが読めない。
	 */
	public function test_anonymous_cannot_read_draft() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $post_id ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * 予約の投稿は、公開の時刻の処理で公開になる。
	 */
	public function test_scheduled_event_is_published_by_cron_handler() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'     => Post_Type::POST_TYPE,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertNotFalse( wp_next_scheduled( 'publish_future_post', array( $post_id ) ) );

		// 時刻が来た状態にして、WP-Cron が呼ぶ処理を流す。
		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'edit_date'     => true,
			)
		);
		check_and_publish_future_post( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * 有効化で rewrite にイベントの規則が入り、無効化で規則の元の登録が外れる。
	 */
	public function test_rewrite_rules_follow_activation() {
		global $wp_rewrite;

		$this->set_permalink_structure( '/%postname%/' );

		Plugin::activate();
		$this->assertArrayHasKey( Post_Type::POST_TYPE, $wp_rewrite->extra_permastructs );
		$this->assertArrayHasKey( 'event/([^/]+)(?:/([0-9]+))?/?$', (array) get_option( 'rewrite_rules' ) );

		Plugin::deactivate();
		$this->assertArrayNotHasKey( Post_Type::POST_TYPE, $wp_rewrite->extra_permastructs );
		$this->assertNull( get_post_type_object( Post_Type::POST_TYPE ) );
	}

	/**
	 * ネットワーク全体の有効化で、ほかのサイトにも権限が付き、rewrite が作り直される。
	 *
	 * @group ms-required
	 */
	public function test_network_activate_grants_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$other_site = self::factory()->blog->create();
		switch_to_blog( $other_site );
		Capabilities::remove_from_all_roles();
		update_option( 'rewrite_rules', array( 'stale' => 'index.php' ) );
		restore_current_blog();

		Plugin::activate( true );

		switch_to_blog( $other_site );
		$has_cap = get_role( 'editor' )->has_cap( 'edit_eventwatch_events' );
		$rules   = get_option( 'rewrite_rules' );
		restore_current_blog();

		$this->assertTrue( $has_cap );
		$this->assertArrayNotHasKey( 'stale', (array) $rules );
	}

	/**
	 * ネットワーク全体で有効なら、あとから作ったサイトにも権限が付く。そうでなければ付かない。
	 *
	 * @group ms-required
	 */
	public function test_new_site_gets_capabilities_only_when_network_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$inactive_site = self::factory()->blog->create();
		switch_to_blog( $inactive_site );
		$has_cap_inactive = (bool) get_role( 'editor' )->has_cap( 'edit_eventwatch_events' );
		restore_current_blog();

		$plugin = plugin_basename( \EventWatch\PLUGIN_FILE );
		update_site_option( 'active_sitewide_plugins', array( $plugin => time() ) );

		$active_site = self::factory()->blog->create();
		switch_to_blog( $active_site );
		$has_cap_active = (bool) get_role( 'editor' )->has_cap( 'edit_eventwatch_events' );
		restore_current_blog();

		delete_site_option( 'active_sitewide_plugins' );

		$this->assertFalse( $has_cap_inactive );
		$this->assertTrue( $has_cap_active );
	}
}
