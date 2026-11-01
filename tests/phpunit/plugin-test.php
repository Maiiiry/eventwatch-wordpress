<?php
/**
 * 有効化・無効化のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Plugin;
use WP_UnitTestCase;

/**
 * CRON_HOOKS は本体では空なので、仮のフック名で無効化の動きを確かめる。
 */
class Plugin_With_Cron_Hook extends Plugin {
	const CRON_HOOKS = array( 'eventwatch_test_hook' );
}

/**
 * Plugin のテスト。
 */
class Plugin_Test extends WP_UnitTestCase {

	/**
	 * 登録したフックが無効化で外れ、登録していないフックは残る。
	 */
	public function test_deactivate_clears_only_registered_cron_hooks() {
		wp_schedule_event( time() + 3600, 'daily', 'eventwatch_test_hook' );
		wp_schedule_event( time() + 3600, 'daily', 'unrelated_test_hook' );

		Plugin_With_Cron_Hook::deactivate();

		$this->assertFalse( wp_next_scheduled( 'eventwatch_test_hook' ) );
		$this->assertNotFalse( wp_next_scheduled( 'unrelated_test_hook' ) );

		wp_clear_scheduled_hook( 'unrelated_test_hook' );
	}

	/**
	 * 無効化で設定は消えない。
	 */
	public function test_deactivate_keeps_settings() {
		update_option( 'eventwatch_settings', 'keep' );

		Plugin::deactivate();

		$this->assertSame( 'keep', get_option( 'eventwatch_settings' ) );
	}

	/**
	 * 有効化・無効化で rewrite が作り直される。
	 */
	public function test_activate_and_deactivate_flush_rewrite_rules() {
		$this->set_permalink_structure( '/%postname%/' );

		foreach ( array( 'activate', 'deactivate' ) as $method ) {
			update_option( 'rewrite_rules', array( 'stale' => 'index.php' ) );

			Plugin::$method();

			$this->assertArrayNotHasKey( 'stale', (array) get_option( 'rewrite_rules' ), $method );
		}
	}

	/**
	 * ネットワーク全体の無効化で、ほかのサイトの cron も外れる。
	 *
	 * @group ms-required
	 */
	public function test_network_deactivate_clears_cron_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$other_site = self::factory()->blog->create();
		switch_to_blog( $other_site );
		wp_schedule_event( time() + 3600, 'daily', 'eventwatch_test_hook' );
		restore_current_blog();

		Plugin_With_Cron_Hook::deactivate( true );

		switch_to_blog( $other_site );
		$next = wp_next_scheduled( 'eventwatch_test_hook' );
		restore_current_blog();

		$this->assertFalse( $next );
	}
}
