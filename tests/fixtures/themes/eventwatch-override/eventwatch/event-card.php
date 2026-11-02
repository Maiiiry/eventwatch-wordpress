<?php
/**
 * テーマで差し替えたカード。
 *
 * @package EventWatch
 */

?>
<a class="override-card" href="<?php echo esc_url( $args['url'] ); ?>"><?php echo wp_kses_post( $args['title'] ); ?></a>
