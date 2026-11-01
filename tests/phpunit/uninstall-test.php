<?php
/**
 * 削除のときの後始末のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use WP_UnitTestCase;

/**
 * uninstall.php のテスト。
 */
class Uninstall_Test extends WP_UnitTestCase {

	/**
	 * uninstall.php を流す。
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'eventwatch/eventwatch.php' );
		}
		global $wpdb;
		require dirname( __DIR__, 2 ) . '/uninstall.php';
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
	}
}
