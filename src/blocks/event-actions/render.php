<?php
/**
 * イベントの操作の列の描画。
 *
 * @package EventWatch
 *
 * @var array  $attributes ブロックの属性。
 * @var string $content    中のブロックの HTML。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// パスワード保護などで中身が無ければ、空の枠を残さない。
if ( '' === trim( $content ) ) {
	return;
}

printf(
	'<div %1$s role="group" aria-label="%2$s">%3$s</div>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 本体がエスケープした属性。
	get_block_wrapper_attributes(),
	esc_attr__( 'Event actions', 'eventwatch' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中のブロックが描いた HTML。
	$content
);
