<?php
/**
 * イベント一覧のブロックの描画。
 *
 * @package EventWatch
 *
 * @var array $attributes ブロックの属性。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Event_List::render の中で値ごとにエスケープしている。
echo \EventWatch\Event_List::render( $attributes );
