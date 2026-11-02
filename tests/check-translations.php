<?php
/**
 * 同梱の ja の翻訳が、いまのソースに揃っているかを確かめる。`npm run i18n:check` で流す。
 *
 * ビルドの後に流す（JS の文字列はビルド後のファイルから読む）。足りなければ終了コード 1 で止まる。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once ABSPATH . WPINC . '/pomo/mo.php';

$eventwatch_root = dirname( __DIR__ );
$eventwatch_pot  = get_temp_dir() . 'eventwatch-check-' . wp_generate_password( 8, false ) . '.pot';

$eventwatch_result = WP_CLI::launch_self(
	'i18n make-pot',
	array( $eventwatch_root, $eventwatch_pot ),
	array( 'exclude' => 'src,tests,vendor,node_modules' ),
	false,
	true
);
if ( 0 !== $eventwatch_result->return_code ) {
	WP_CLI::error( 'make-pot failed: ' . $eventwatch_result->stderr );
}

$eventwatch_source = new PO();
$eventwatch_ja     = new PO();
$eventwatch_mo     = new MO();
$eventwatch_loaded = $eventwatch_source->import_from_file( $eventwatch_pot )
	&& $eventwatch_ja->import_from_file( $eventwatch_root . '/languages/eventwatch-ja.po' )
	&& $eventwatch_mo->import_from_file( $eventwatch_root . '/languages/eventwatch-ja.mo' );
wp_delete_file( $eventwatch_pot );
if ( ! $eventwatch_loaded ) {
	WP_CLI::error( 'Could not read the translation files. Run: npm run i18n' );
}

$eventwatch_problems = array();

foreach ( $eventwatch_source->entries as $eventwatch_key => $eventwatch_entry ) {
	$eventwatch_translated = isset( $eventwatch_ja->entries[ $eventwatch_key ] ) ? $eventwatch_ja->entries[ $eventwatch_key ]->translations : array();
	if ( ! array_filter( $eventwatch_translated, 'strlen' ) ) {
		$eventwatch_problems[] = 'Not translated: ' . $eventwatch_entry->singular;
		continue;
	}

	$eventwatch_compiled = isset( $eventwatch_mo->entries[ $eventwatch_key ] ) ? $eventwatch_mo->entries[ $eventwatch_key ]->translations : array();
	if ( array_values( $eventwatch_translated ) !== array_values( $eventwatch_compiled ) ) {
		$eventwatch_problems[] = 'The MO file is out of date: ' . $eventwatch_entry->singular;
	}
}

// 本体は JSON の名前をスクリプトの相対パスの md5 で引く。文字列を持つスクリプトの分がそろっていること。
foreach ( array( 'build/index.js', 'build/blocks/event-list/index.js', 'build/blocks/event-search/index.js' ) as $eventwatch_script ) {
	$eventwatch_json = $eventwatch_root . '/languages/eventwatch-ja-' . md5( $eventwatch_script ) . '.json';
	if ( ! is_readable( $eventwatch_json ) ) {
		$eventwatch_problems[] = 'Missing script translations for ' . $eventwatch_script;
	}
}

if ( $eventwatch_problems ) {
	WP_CLI::error( implode( "\n", $eventwatch_problems ) . "\nFix languages/eventwatch-ja.po, then run: npm run i18n" );
}

WP_CLI::success( count( $eventwatch_source->entries ) . ' strings are translated.' );
