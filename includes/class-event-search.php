<?php
/**
 * イベントの検索の条件と描画。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 条件は URL のクエリだけが持つ。JS の有無で同じ結果になるよう、結果は常にサーバーが描く。
 */
class Event_Search {

	const PARAM_KEYWORD    = 'eventwatch_q';
	const PARAM_FROM       = 'eventwatch_from';
	const PARAM_TO         = 'eventwatch_to';
	const PARAM_CATEGORY   = 'eventwatch_cat';
	const PARAM_PREFECTURE = 'eventwatch_pref';
	const PARAM_PAGE       = 'eventwatch_page';

	const MAX_KEYWORD_LENGTH = 100;
	const MAX_KEYWORD_TERMS  = 8;

	const REGION = 'eventwatch-event-search';

	/**
	 * この要求で描いたブロックの数。
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * 属性と URL の条件で描く。ブロックの描画の中で呼ぶ。
	 *
	 * @param array $attributes ブロックの属性。
	 * @return string
	 */
	public static function render( $attributes ) {
		++self::$instances;
		// 同じ名前の region が 2 つあると Router が片方を消すので、2 つ目は読み直す形で動かす。
		$interactive = 1 === self::$instances;
		$suffix      = $interactive ? '' : '-' . self::$instances;
		$anchor      = 'eventwatch-search-results' . $suffix;

		$raw        = is_array( $attributes ) ? $attributes : array();
		$conditions = self::conditions();
		$list       = Event_List::normalize(
			array(
				'layout'     => isset( $raw['layout'] ) ? $raw['layout'] : Event_List::LAYOUT_CARDS,
				'perPage'    => isset( $raw['perPage'] ) ? $raw['perPage'] : Event_List::DEFAULT_SIZE,
				'prefecture' => $conditions['prefecture'],
				'dateFrom'   => $conditions['from'],
				'dateTo'     => $conditions['to'],
			)
		);

		$categories = self::category_options( $conditions['category'] );
		if ( self::has_category_terms() ) {
			$list['categoryId'] = $conditions['category'];
		} else {
			$list['eventCategory'] = $conditions['category'];
		}
		$list['keyword'] = self::keyword_terms( $conditions['keyword'] );

		list( $query, $page ) = Event_List::query_for_request( $list, self::PARAM_PAGE );

		$wrapper = array(
			'class' => 'wp-block-eventwatch-event-list is-layout-' . $list['layout'],
		);
		if ( $interactive ) {
			$wrapper['data-wp-interactive']   = 'eventwatch/event-search';
			$wrapper['data-wp-router-region'] = self::REGION;
			$wrapper['data-wp-context']       = '{"busy":false}';
			$wrapper['data-wp-on--click']     = 'actions.follow';
		}

		$base = self::base_url();

		ob_start();
		load_template(
			plugin_dir_path( PLUGIN_FILE ) . 'templates/event-search-form.php',
			false,
			array(
				'conditions'  => $conditions,
				'categories'  => $categories,
				'prefectures' => self::prefecture_options( $conditions['prefecture'] ),
				'action'      => strtok( $base, '?' ),
				'hidden'      => self::hidden_fields( $base ),
				'clear_url'   => $base,
				'suffix'      => $suffix,
				'interactive' => $interactive,
			)
		);
		$form = (string) ob_get_clean();

		$html  = '<div ' . get_block_wrapper_attributes( $wrapper ) . '>' . $form;
		$html .= self::render_status( $query, $anchor );
		$html .= '<div class="eventwatch-search__results"' . ( $interactive ? ' data-wp-bind--aria-busy="context.busy"' : '' ) . '>';
		$html .= $query->posts
			? Event_List::render_results( $list, $query, self::PARAM_PAGE, $anchor, $page )
			: self::render_empty( $conditions, $base );

		return $html . '</div></div>';
	}

	/**
	 * URL のクエリを検証した検索の条件。
	 *
	 * 実在しない値は、無かったものとして扱う。欄の表示と結果を食い違わせないため。
	 *
	 * @return array{keyword: string, from: string, to: string, category: int, prefecture: int}
	 */
	public static function conditions() {
		$keyword = mb_substr( self::request( self::PARAM_KEYWORD ), 0, self::MAX_KEYWORD_LENGTH );
		$from    = self::request( self::PARAM_FROM );
		$from    = null !== Event_List::parse_date( $from ) ? $from : '';
		$to      = self::request( self::PARAM_TO );
		$to      = null !== Event_List::parse_date( $to ) ? $to : '';

		if ( '' !== $from && '' !== $to && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$category = absint( self::request( self::PARAM_CATEGORY ) );
		if ( ! self::category_exists( $category ) ) {
			$category = 0;
		}

		$prefecture = absint( self::request( self::PARAM_PREFECTURE ) );
		if ( ! array_key_exists( $prefecture, Event_Options::prefectures() ) ) {
			$prefecture = 0;
		}

		return array(
			'keyword'    => $keyword,
			'from'       => $from,
			'to'         => $to,
			'category'   => $category,
			'prefecture' => $prefecture,
		);
	}

	/**
	 * キーワードを語に分ける。全角の空白でも分ける。
	 *
	 * @param string $keyword キーワード。
	 * @return string[]
	 */
	public static function keyword_terms( $keyword ) {
		$terms = preg_split( '/[\s\x{3000}]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY );

		return array_slice( $terms ? $terms : array(), 0, self::MAX_KEYWORD_TERMS );
	}

	/**
	 * カテゴリの選択肢。サイトのカテゴリに語があれば語、無ければ開催予定のイベントが持つ EventWatch のカテゴリ。
	 *
	 * カードの表示（サイトの語、無ければ EventWatch の語）に揃える。
	 *
	 * @param int $selected 選択中の値。
	 * @return array<int, string>
	 */
	public static function category_options( $selected = 0 ) {
		if ( self::has_category_terms() ) {
			$options = array();
			foreach ( self::category_terms() as $term ) {
				$options[ (int) $term->term_id ] = $term->name;
			}
			return $options;
		}

		$values = self::upcoming_meta_values( Event_Meta::CATEGORY );
		if ( $selected > 0 ) {
			$values[] = $selected;
		}

		return array_intersect_key( Event_Options::categories(), array_flip( $values ) );
	}

	/**
	 * 都道府県の選択肢。開催予定のイベントがあるものと、選択中のもの。
	 *
	 * @param int $selected 選択中の値。
	 * @return array<int, string>
	 */
	public static function prefecture_options( $selected = 0 ) {
		$values = self::upcoming_meta_values( Event_Meta::PREFECTURE );
		if ( $selected > 0 ) {
			$values[] = $selected;
		}

		return array_intersect_key( Event_Options::prefectures(), array_flip( $values ) );
	}

	/**
	 * 件数の文。差し替えの前後で同じ要素に残るので、読み上げられる。
	 *
	 * @param WP_Query $query  クエリ。
	 * @param string   $anchor ページ送りの着地先の id。
	 * @return string
	 */
	private static function render_status( WP_Query $query, $anchor ) {
		$count = (int) $query->found_posts;
		/* translators: %s: the number of events found. */
		$text = sprintf( _n( '%s event', '%s events', $count, 'eventwatch' ), number_format_i18n( $count ) );

		return '<p class="eventwatch-search__status" id="' . esc_attr( $anchor ) . '" role="status" tabindex="-1">' . esc_html( $text ) . '</p>';
	}

	/**
	 * 0 件の表示（板 2b）。条件が無いときは外す条件が無いので、一覧の 0 件と同じ文だけを出す。
	 *
	 * @param array  $conditions 条件。
	 * @param string $base       条件を外した URL。
	 * @return string
	 */
	private static function render_empty( array $conditions, $base ) {
		// キーワードの '0' は条件なので、array_filter の真偽で判定しない。
		if ( '' === $conditions['keyword'] && '' === $conditions['from'] && '' === $conditions['to'] && 0 === $conditions['category'] && 0 === $conditions['prefecture'] ) {
			return '<div class="eventwatch-events__empty"><p>' . esc_html__( 'No upcoming events yet.', 'eventwatch' ) . '</p></div>';
		}

		return '<div class="eventwatch-events__empty"><p>' . esc_html__( 'No events match your search.', 'eventwatch' ) . '</p>'
			. '<p class="eventwatch-search__hint">' . esc_html__( 'Try a shorter keyword, or remove the date or category filter.', 'eventwatch' ) . '</p>'
			. '<a class="eventwatch-search__clear" href="' . esc_url( $base ) . '">' . esc_html__( 'Clear filters', 'eventwatch' ) . '</a></div>';
	}

	/**
	 * 今の URL から、検索の条件とページを除いたもの。
	 *
	 * @return string
	 */
	private static function base_url() {
		return remove_query_arg(
			array( self::PARAM_KEYWORD, self::PARAM_FROM, self::PARAM_TO, self::PARAM_CATEGORY, self::PARAM_PREFECTURE, self::PARAM_PAGE ),
			Event_List::current_uri()
		);
	}

	/**
	 * フォームの hidden。GET のフォームは action のクエリを捨てるので、基本のパーマリンクの page_id などを送り直す。
	 *
	 * @param string $base 条件を外した URL。
	 * @return array<string, string>
	 */
	private static function hidden_fields( $base ) {
		$query = wp_parse_url( $base, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return array();
		}

		wp_parse_str( $query, $values );

		return array_filter( $values, 'is_scalar' );
	}

	/**
	 * 語のあるサイトのカテゴリ。
	 *
	 * @return \WP_Term[]
	 */
	private static function category_terms() {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Type::TAX_CATEGORY,
				'hide_empty' => true,
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * サイトのカテゴリに語があるか。
	 *
	 * @return bool
	 */
	private static function has_category_terms() {
		return (bool) self::category_terms();
	}

	/**
	 * カテゴリの値が今の選択肢の種類に実在するか。
	 *
	 * @param int $value 値。
	 * @return bool
	 */
	private static function category_exists( $value ) {
		if ( $value <= 0 ) {
			return false;
		}

		if ( self::has_category_terms() ) {
			return in_array( $value, array_map( 'intval', wp_list_pluck( self::category_terms(), 'term_id' ) ), true );
		}

		return array_key_exists( $value, Event_Options::categories() );
	}

	/**
	 * 開催予定で公開中のイベントが持つメタの値。
	 *
	 * @param string $key メタのキー。
	 * @return int[]
	 */
	private static function upcoming_meta_values( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値の一覧は WP_Query で引けない。投稿の数に応じて全件を読まずに済む。
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT value.meta_value
				FROM {$wpdb->postmeta} AS value
				INNER JOIN {$wpdb->posts} AS post ON post.ID = value.post_id
				INNER JOIN {$wpdb->postmeta} AS ending ON ending.post_id = post.ID AND ending.meta_key = %s
				WHERE value.meta_key = %s
					AND post.post_type = %s
					AND post.post_status = 'publish'
					AND post.post_password = ''
					AND CAST(ending.meta_value AS SIGNED) >= %d",
				Event_Meta::LAST_END,
				$key,
				Post_Type::POST_TYPE,
				Event_Dates::upcoming_boundary()
			)
		);

		return array_map( 'intval', $values );
	}

	/**
	 * URL のクエリの文字列の値。配列で渡された値は捨てる。
	 *
	 * @param string $name 名前。
	 * @return string
	 */
	private static function request( $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 検索の条件を読むだけで、状態を変えない。
		if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
		// phpcs:enable
	}
}
