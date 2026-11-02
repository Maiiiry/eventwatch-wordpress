<?php
/**
 * 検索のフォーム。
 *
 * テーマからは差し替えられない。Router への受け渡しの属性を保つため。
 * autocomplete を止めるのは、戻ったときにブラウザーが打った値を復元して、URL の条件と欄が食い違うのを防ぐため。
 * select の value は HTML の属性ではないが、差し替えで条件と欄を揃えるために要る。Router の差分は前の描画としか比べないので、送信しなかった選択が selected だけでは戻らない。
 *
 * $args: conditions（Event_Search::conditions()）・categories・prefectures（値と語）・action・hidden（名前と値）・clear_url・suffix・interactive。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eventwatch_conditions = $args['conditions'];
$eventwatch_suffix     = $args['suffix'];
?>
<form
	class="eventwatch-search__form"
	role="search"
	method="get"
	autocomplete="off"
	action="<?php echo esc_url( $args['action'] ); ?>"
	aria-label="<?php esc_attr_e( 'Search events', 'eventwatch' ); ?>"
	<?php if ( $args['interactive'] ) : ?>
		data-wp-on--submit="actions.submit"
	<?php endif; ?>
>
	<?php foreach ( $args['hidden'] as $eventwatch_name => $eventwatch_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $eventwatch_name ); ?>" value="<?php echo esc_attr( $eventwatch_value ); ?>" />
	<?php endforeach; ?>
	<div class="eventwatch-search__field eventwatch-search__field--wide">
		<label class="eventwatch-search__label" for="eventwatch-search-keyword<?php echo esc_attr( $eventwatch_suffix ); ?>"><?php esc_html_e( 'Keyword', 'eventwatch' ); ?></label>
		<input
			class="eventwatch-search__input"
			id="eventwatch-search-keyword<?php echo esc_attr( $eventwatch_suffix ); ?>"
			type="search"
			name="<?php echo esc_attr( \EventWatch\Event_Search::PARAM_KEYWORD ); ?>"
			value="<?php echo esc_attr( $eventwatch_conditions['keyword'] ); ?>"
			placeholder="<?php esc_attr_e( 'Event or venue name', 'eventwatch' ); ?>"
			maxlength="<?php echo esc_attr( \EventWatch\Event_Search::MAX_KEYWORD_LENGTH ); ?>"
		/>
	</div>
	<fieldset class="eventwatch-search__field eventwatch-search__field--wide">
		<legend class="eventwatch-search__label"><?php echo esc_html_x( 'Dates', 'search period', 'eventwatch' ); ?></legend>
		<div class="eventwatch-search__period">
			<input
				class="eventwatch-search__input"
				type="date"
				name="<?php echo esc_attr( \EventWatch\Event_Search::PARAM_FROM ); ?>"
				value="<?php echo esc_attr( $eventwatch_conditions['from'] ); ?>"
				aria-label="<?php esc_attr_e( 'From', 'eventwatch' ); ?>"
			/>
			<span aria-hidden="true"><?php echo esc_html_x( '–', 'between the search dates', 'eventwatch' ); ?></span>
			<input
				class="eventwatch-search__input"
				type="date"
				name="<?php echo esc_attr( \EventWatch\Event_Search::PARAM_TO ); ?>"
				value="<?php echo esc_attr( $eventwatch_conditions['to'] ); ?>"
				aria-label="<?php esc_attr_e( 'To', 'eventwatch' ); ?>"
			/>
		</div>
	</fieldset>
	<div class="eventwatch-search__field">
		<label class="eventwatch-search__label" for="eventwatch-search-category<?php echo esc_attr( $eventwatch_suffix ); ?>"><?php esc_html_e( 'Category', 'eventwatch' ); ?></label>
		<div class="eventwatch-search__select">
			<select class="eventwatch-search__input" id="eventwatch-search-category<?php echo esc_attr( $eventwatch_suffix ); ?>" name="<?php echo esc_attr( \EventWatch\Event_Search::PARAM_CATEGORY ); ?>" value="<?php echo esc_attr( $eventwatch_conditions['category'] ? $eventwatch_conditions['category'] : '' ); ?>">
				<option value=""><?php esc_html_e( 'All', 'eventwatch' ); ?></option>
				<?php foreach ( $args['categories'] as $eventwatch_value => $eventwatch_label ) : ?>
					<option value="<?php echo esc_attr( $eventwatch_value ); ?>" <?php selected( $eventwatch_conditions['category'], $eventwatch_value ); ?>><?php echo esc_html( $eventwatch_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="eventwatch-search__field">
		<label class="eventwatch-search__label" for="eventwatch-search-prefecture<?php echo esc_attr( $eventwatch_suffix ); ?>"><?php esc_html_e( 'Prefecture', 'eventwatch' ); ?></label>
		<div class="eventwatch-search__select">
			<select class="eventwatch-search__input" id="eventwatch-search-prefecture<?php echo esc_attr( $eventwatch_suffix ); ?>" name="<?php echo esc_attr( \EventWatch\Event_Search::PARAM_PREFECTURE ); ?>" value="<?php echo esc_attr( $eventwatch_conditions['prefecture'] ? $eventwatch_conditions['prefecture'] : '' ); ?>">
				<option value=""><?php esc_html_e( 'All', 'eventwatch' ); ?></option>
				<?php foreach ( $args['prefectures'] as $eventwatch_value => $eventwatch_label ) : ?>
					<option value="<?php echo esc_attr( $eventwatch_value ); ?>" <?php selected( $eventwatch_conditions['prefecture'], $eventwatch_value ); ?>><?php echo esc_html( $eventwatch_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="eventwatch-search__actions">
		<button class="eventwatch-search__submit" type="submit"><?php esc_html_e( 'Search', 'eventwatch' ); ?></button>
		<a class="eventwatch-search__clear" href="<?php echo esc_url( $args['clear_url'] ); ?>"><?php esc_html_e( 'Clear filters', 'eventwatch' ); ?></a>
	</div>
</form>
