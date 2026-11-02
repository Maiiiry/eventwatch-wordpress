<?php
/**
 * テーマで差し替えたカード。
 *
 * @package EventWatch
 */

?>
<a class="override-card" href="<?php echo esc_url( $args['url'] ); ?>"><?php echo esc_html( wp_strip_all_tags( $args['title'] ) ); ?></a>
