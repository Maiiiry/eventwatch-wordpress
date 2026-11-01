<?php
/**
 * プラグインの有効化と無効化。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化・無効化のときの後始末を持つ。
 *
 * 設定は無効化では消さない。消すのは削除のとき（uninstall.php）だけ。
 */
class Plugin {

	/**
	 * プラグインが登録する cron のフック名。
	 *
	 * 登録するものを足したら、ここにも足す。無効化で外れなくなるため。
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array();

	/**
	 * 有効化のときに rewrite を作り直す。
	 *
	 * @return void
	 */
	public static function activate() {
		flush_rewrite_rules();
	}

	/**
	 * 無効化のときに cron を外し、rewrite を作り直す。
	 *
	 * @param bool $network_wide ネットワーク全体で無効化したか。
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				static::clear_cron_hooks();
				// switch_to_blog では rewrite の定義が切り替わらないので、flush せずに消して次の読み込みで作らせる。
				delete_option( 'rewrite_rules' );
				restore_current_blog();
			}
			return;
		}

		static::clear_cron_hooks();
		flush_rewrite_rules();
	}

	/**
	 * いまのサイトの cron を外す。
	 *
	 * @return void
	 */
	private static function clear_cron_hooks() {
		foreach ( static::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
