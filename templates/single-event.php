<?php
/**
 * クラシックテーマのイベント詳細のページ。
 *
 * テーマの eventwatch/single-event.php か single-eventwatch_event.php を置くと差し替えられる。
 * 欄だけを使うなら、EventWatch\Event_Detail::default_content() の中のブロックを do_blocks で描く。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) {
	the_post();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ブロックの描画の中で値ごとにエスケープしている。
	echo do_blocks( \EventWatch\Event_Detail::default_content() );
}

get_footer();
