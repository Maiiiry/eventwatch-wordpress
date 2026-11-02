<?php
/**
 * イベントの検索のブロックの描画。
 *
 * @package EventWatch
 *
 * @var array $attributes ブロックの属性。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Event_Search::render の中で値ごとにエスケープしている。
echo \EventWatch\Event_Search::render( $attributes );
