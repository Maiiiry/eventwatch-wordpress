<?php
/**
 * プラグインを削除するときの後始末。
 *
 * option と transient だけを消す。利用者が登録したイベントの投稿は、利用者のデータなので消さない。
 *
 * @package EventWatch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 本体の uninstall_plugin() は、global を宣言していない関数の中でこのファイルを読み込む。
global $wpdb;

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

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $eventwatch_site_id ) {
		switch_to_blog( $eventwatch_site_id );
		$eventwatch_delete_site_options();
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
	$eventwatch_delete_site_options();
}
