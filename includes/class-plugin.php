<?php
/**
 * プラグインの読み込みと、有効化・無効化。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フックの登録と、有効化・無効化のときの後始末を持つ。
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
	const CRON_HOOKS = array( Sync_Queue::RUN_HOOK, Sync_Queue::RESCAN_HOOK, Sync_Pull::RUN_HOOK );

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( static::class, 'load_translations' ), 0 );
		add_action( 'init', array( Post_Type::class, 'register' ) );
		add_action( 'init', array( Event_List_Block::class, 'register' ) );
		add_action( 'init', array( Event_Search_Block::class, 'register' ) );
		add_action( 'init', array( Past_Events_Block::class, 'register' ) );
		add_action( 'init', array( Event_Patterns::class, 'register' ) );
		add_action( 'init', array( Event_Detail::class, 'register' ) );
		add_filter( 'posts_where', array( Event_List::class, 'filter_where' ), 10, 2 );
		add_action( 'after_setup_theme', array( Post_Type::class, 'add_thumbnail_support' ), 100 );
		// 本体の wp_initialize_site（優先度 10）が表を作った後に動かす。
		add_action( 'wp_initialize_site', array( static::class, 'initialize_site' ), 100 );
		Event_Meta::boot();
		Event_Permalink::boot();
		Event_Detail::boot();
		Settings::boot();
		Connection_Admin::boot();
		Sync_Queue::boot();
		Sync_Pull::boot();
		Sync_Rest::boot();
		Admin_Columns::boot();
		Editor_Assets::boot();
	}

	/**
	 * 同梱の翻訳の場所を本体に伝える。
	 *
	 * 公式ディレクトリの翻訳が届いていれば、本体はそちらを先に読む。
	 *
	 * @return void
	 */
	public static function load_translations() {
		load_plugin_textdomain( 'eventwatch', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * 有効化のときに権限を付け、rewrite を作り直す。
	 *
	 * @param bool $network_wide ネットワーク全体で有効化したか。
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( static::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				Capabilities::grant();
				Site_Identity::ensure();
				Sync_Queue::schedule_next();
				static::schedule_pull();
				// switch_to_blog では rewrite の定義が切り替わらないので、flush せずに消して次の読み込みで作らせる。
				delete_option( 'rewrite_rules' );
				restore_current_blog();
			}
			return;
		}

		Capabilities::grant();
		Site_Identity::ensure();
		Sync_Queue::schedule_next();
		static::schedule_pull();
		Post_Type::register();
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
			foreach ( static::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				static::clear_cron_hooks();
				// switch_to_blog では rewrite の定義が切り替わらないので、flush せずに消して次の読み込みで作らせる。
				delete_option( 'rewrite_rules' );
				restore_current_blog();
			}
			return;
		}

		static::clear_cron_hooks();
		// 登録が残ったまま flush すると、イベントの rewrite が作り直した規則に残る。
		Post_Type::unregister();
		flush_rewrite_rules();
	}

	/**
	 * ネットワーク全体で有効なときに、新しいサイトにも権限を付ける。
	 *
	 * rewrite は、新しいサイトの最初の読み込みで作られる。
	 *
	 * @param \WP_Site $site 作られたサイト。
	 * @return void
	 */
	public static function initialize_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->id );
		Capabilities::grant();
		Site_Identity::ensure();
		restore_current_blog();
	}

	/**
	 * いまのネットワークのサイトの ID。
	 *
	 * @return int[]
	 */
	private static function network_site_ids() {
		return get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		);
	}

	/**
	 * 無効化で外した定期の取り込みを張り直す。キーなしのサイトには予定を増やさない。
	 *
	 * @return void
	 */
	private static function schedule_pull() {
		if ( '' !== Connection::api_key() ) {
			Sync_Pull::schedule();
		}
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
