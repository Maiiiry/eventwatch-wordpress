<?php
/**
 * 編集画面のサイドバーの読み込み。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * イベントの編集画面でだけ、ビルドしたスクリプトを読み込む。
 */
class Editor_Assets {

	const HANDLE = 'eventwatch-event-editor';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * スクリプトとスタイルを読み込む。
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_path = plugin_dir_path( PLUGIN_FILE ) . 'build/index.asset.php';
		// ビルドしていない作業ツリーでも、管理画面を壊さない。
		if ( ! is_readable( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'eventwatch' );
		wp_add_inline_script(
			self::HANDLE,
			'window.eventwatchEditor = ' . wp_json_encode(
				array(
					'categories'  => self::to_options( Event_Options::categories() ),
					'prefectures' => self::to_options( Event_Options::prefectures() ),
				)
			) . ';',
			'before'
		);
		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'build/index.css', PLUGIN_FILE ),
			array(),
			$asset['version']
		);
	}

	/**
	 * 値と語の連想配列を、SelectControl の選択肢の形にする。
	 *
	 * @param array<int, string> $labels 値と語。
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function to_options( array $labels ) {
		$options = array();
		foreach ( $labels as $value => $label ) {
			$options[] = array(
				'value' => (string) $value,
				'label' => $label,
			);
		}
		return $options;
	}
}
