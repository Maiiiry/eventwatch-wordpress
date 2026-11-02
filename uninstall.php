<?php
/**
 * プラグインを削除するときの後始末。
 *
 * option と transient を消し、役割から権限を外す。利用者が登録したイベントの投稿は、利用者のデータなので
 * 既定では消さない。option eventwatch_delete_events_on_uninstall を立てたサイトでだけ消す。
 *
 * @package EventWatch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 本体の uninstall_plugin() は、global を宣言していない関数の中でこのファイルを読み込む。
global $wpdb;

// 削除のときは本体のファイルが読み込まれないので、自動の読み込みも効かない。
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-post-type.php';

$eventwatch_like_patterns = array_map(
	static function ( string $prefix ): string {
		global $wpdb;
		return $wpdb->esc_like( $prefix ) . '%';
	},
	array(
		'eventwatch_',
		'_transient_eventwatch_',
		'_transient_timeout_eventwatch_',
		'_site_transient_eventwatch_',
		'_site_transient_timeout_eventwatch_',
	)
);

$eventwatch_delete_site_options = static function () use ( $eventwatch_like_patterns ): void {
	global $wpdb;

	$where = implode( ' OR ', array_fill( 0, count( $eventwatch_like_patterns ), 'option_name LIKE %s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- 接頭辞で引く API が無い。$where はプレースホルダーだけでできている。
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $eventwatch_like_patterns ) );
	// phpcs:enable

	foreach ( $names as $name ) {
		delete_option( $name );
	}
};

$eventwatch_clean_site = static function () use ( $eventwatch_delete_site_options ): void {
	EventWatch\Capabilities::remove_from_all_roles();

	// 設定も消すので、消す前に読む。
	if ( get_option( 'eventwatch_delete_events_on_uninstall' ) ) {
		EventWatch\Post_Type::delete_all_content();
	}

	$eventwatch_delete_site_options();
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $eventwatch_site_id ) {
		switch_to_blog( $eventwatch_site_id );
		$eventwatch_clean_site();
		restore_current_blog();
	}

	// プラグインのファイルはネットワークをまたいで共通なので、すべてのネットワークの option を消す。
	$eventwatch_where = implode( ' OR ', array_fill( 0, count( $eventwatch_like_patterns ), 'meta_key LIKE %s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.SlowDBQuery -- 接頭辞で引く API が無い。$eventwatch_where はプレースホルダーだけでできている。
	$eventwatch_network_options = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, meta_key FROM {$wpdb->sitemeta} WHERE {$eventwatch_where}", $eventwatch_like_patterns ) );
	// phpcs:enable

	foreach ( $eventwatch_network_options as $eventwatch_network_option ) {
		delete_network_option( (int) $eventwatch_network_option->site_id, $eventwatch_network_option->meta_key );
	}
} else {
	$eventwatch_clean_site();
}
