<?php
/**
 * イベント詳細の欄の描画。
 *
 * @package EventWatch
 *
 * @var array     $attributes ブロックの属性。
 * @var WP_Block  $block      ブロック。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Event_Detail の中で値ごとにエスケープしている。
echo \EventWatch\Event_Detail::render_block( $attributes, $block );
