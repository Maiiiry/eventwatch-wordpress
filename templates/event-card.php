<?php
/**
 * イベント 1 件のカード。
 *
 * テーマの eventwatch/event-card.php を置くと差し替えられる。
 * $args の値は Event_List::item_data() を参照。title と image は HTML で、ほかは文字列。
 *
 * 読み上げがイベント名から始まるよう、DOM ではイベント名を先に置き、見た目の順は CSS で決める。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="eventwatch-event eventwatch-event--card" href="<?php echo esc_url( $args['url'] ); ?>">
	<h3 class="eventwatch-event__title"><?php echo wp_kses_post( $args['title'] ); ?></h3>
	<p class="eventwatch-event__date">
		<time datetime="<?php echo esc_attr( $args['datetime'] ); ?>"><?php echo esc_html( $args['date'] ); ?></time>
		<?php if ( '' !== $args['more'] ) : ?>
			<span class="eventwatch-event__more"><?php echo esc_html( $args['more'] ); ?></span>
		<?php endif; ?>
	</p>
	<p class="eventwatch-event__venue"><?php echo esc_html( $args['venue'] ); ?></p>
	<?php if ( '' !== $args['category'] ) : ?>
		<span class="eventwatch-event__category"><?php echo esc_html( $args['category'] ); ?></span>
	<?php endif; ?>
	<?php if ( '' !== $args['image'] ) : ?>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 本体の get_the_post_thumbnail が作った HTML。 ?>
		<div class="eventwatch-event__media"><?php echo $args['image']; ?></div>
	<?php endif; ?>
</a>
