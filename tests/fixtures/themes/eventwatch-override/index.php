<?php
/**
 * テスト用のテーマの本体。クラシックテーマとして読まれ、本文だけを出す。
 *
 * @package EventWatch
 */

get_header();
while ( have_posts() ) {
	the_post();
	the_content();
}
get_footer();
