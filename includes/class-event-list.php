<?php
/**
 * イベントの一覧の取得と描画。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeImmutable;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ブロックとショートコードが同じ描画を呼ぶ。データはサイトの中だけから読む。
 */
class Event_List {

	const LAYOUT_CARDS = 'cards';
	const LAYOUT_LIST  = 'list';
	const DEFAULT_SIZE = 6;
	const MAX_SIZE     = 24;

	/**
	 * キーワードの語を渡すクエリの変数。
	 */
	const KEYWORD_VAR = 'eventwatch_keyword';

	/**
	 * テーマが置く上書きのテンプレートの置き場。
	 */
	const THEME_DIR = 'eventwatch';

	/**
	 * 属性を検証して描画する。
	 *
	 * ブロックの描画の中で呼ぶ（get_block_wrapper_attributes が描画中のブロックを読むため）。ブロックの外からは render_block を通す。
	 *
	 * @param array $attributes ブロックの属性。
	 * @return string
	 */
	public static function render( $attributes ) {
		$raw        = is_array( $attributes ) ? $attributes : array();
		$attributes = self::normalize( $raw );
		// 色や余白も含めて分ける。絞り込みが同じで色だけ違うブロックを、別の一覧として扱うため。
		$identity = array_merge( $raw, $attributes );
		ksort( $identity );
		$hash   = substr( md5( (string) wp_json_encode( $identity ) ), 0, 8 );
		$param  = 'eventwatch_page_' . $hash;
		$anchor = 'eventwatch-events-' . $hash;
		list( $query, $page ) = self::query_for_request( $attributes, $param );

		$html = '<div ' . get_block_wrapper_attributes(
			array(
				'id'    => $anchor,
				'class' => 'is-layout-' . $attributes['layout'],
			)
		) . '>';

		if ( $query->posts ) {
			$html .= self::render_results( $attributes, $query, $param, $anchor, $page );
		} else {
			$text  = '' !== $attributes['emptyText'] ? $attributes['emptyText'] : __( 'No upcoming events yet.', 'eventwatch' );
			$html .= '<div class="eventwatch-events__empty"><p>' . esc_html( $text ) . '</p></div>';
		}

		return $html . '</div>';
	}

	/**
	 * 要求のページ番号でイベントを引く。
	 *
	 * @param array  $attributes normalize() を通した属性。
	 * @param string $param      ページのクエリの名前。
	 * @return array{0: WP_Query, 1: int} クエリと、実際に引いたページ。
	 */
	public static function query_for_request( array $attributes, $param ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ページの番号を読むだけで、状態を変えない。
		$raw   = isset( $_GET[ $param ] ) && is_scalar( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 1;
		$page  = max( 1, $raw );
		$query = self::query( $attributes, $page );

		// 件数が減ると、共有されたリンクのページが範囲外になる。「まだありません」と誤って出さない。
		// 結果が空だと WP_Query は found_posts を数えないので、件数では判定できない。
		if ( ! $query->posts && $page > 1 ) {
			$page  = 1;
			$query = self::query( $attributes, $page );
		}

		return array( $query, $page );
	}

	/**
	 * 項目の並びとページ送りを描く。
	 *
	 * @param array    $attributes normalize() を通した属性。
	 * @param WP_Query $query      結果のあるクエリ。
	 * @param string   $param      ページのクエリの名前。
	 * @param string   $anchor     ページ送りの着地先の id。
	 * @param int      $page       今のページ。
	 * @return string
	 */
	public static function render_results( array $attributes, WP_Query $query, $param, $anchor, $page ) {
		$html = '<ul class="eventwatch-events__items">';
		foreach ( $query->posts as $post ) {
			$html .= '<li class="eventwatch-events__item">' . self::render_item( $attributes, $post ) . '</li>';
		}

		return $html . '</ul>' . self::render_pagination( $param, $anchor, $page, (int) $query->max_num_pages );
	}

	/**
	 * 属性を、描画に使える値にそろえる。
	 *
	 * 投稿の本文は投稿者が書き換えられるので、block.json の型を頼らない。
	 *
	 * @param array $attributes 属性。
	 * @return array{layout: string, perPage: int, order: string, categoryId: int, tagId: int, prefecture: int, dateFrom: string, dateTo: string, emptyText: string}
	 */
	public static function normalize( array $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'layout'     => self::LAYOUT_CARDS,
				'perPage'    => self::DEFAULT_SIZE,
				'order'      => 'asc',
				'categoryId' => 0,
				'tagId'      => 0,
				'prefecture' => 0,
				'dateFrom'   => '',
				'dateTo'     => '',
				'emptyText'  => '',
			)
		);

		$prefecture = is_scalar( $attributes['prefecture'] ) ? (int) $attributes['prefecture'] : 0;
		$per_page   = is_scalar( $attributes['perPage'] ) ? (int) $attributes['perPage'] : self::DEFAULT_SIZE;

		return array(
			'layout'     => self::LAYOUT_LIST === $attributes['layout'] ? self::LAYOUT_LIST : self::LAYOUT_CARDS,
			'perPage'    => min( self::MAX_SIZE, max( 1, $per_page ) ),
			'order'      => 'desc' === $attributes['order'] ? 'desc' : 'asc',
			'categoryId' => is_scalar( $attributes['categoryId'] ) ? absint( $attributes['categoryId'] ) : 0,
			'tagId'      => is_scalar( $attributes['tagId'] ) ? absint( $attributes['tagId'] ) : 0,
			'prefecture' => array_key_exists( $prefecture, Event_Options::prefectures() ) ? $prefecture : 0,
			'dateFrom'   => null !== self::parse_date( $attributes['dateFrom'] ) ? $attributes['dateFrom'] : '',
			'dateTo'     => null !== self::parse_date( $attributes['dateTo'] ) ? $attributes['dateTo'] : '',
			'emptyText'  => is_string( $attributes['emptyText'] ) ? sanitize_text_field( $attributes['emptyText'] ) : '',
		);
	}

	/**
	 * 開催予定のイベントを引く。
	 *
	 * 配列のメタは比べられないので、判定と並べ替えは UTC の整数のメタで行う。日程の無いイベントはこのメタを持たないので出ない。
	 * 期間は最初の開始と最後の終了の範囲で比べるので、組と組の間の期間にも当たる。並びも最初の開始なので、過ぎた回があるイベントは先に並ぶ。
	 *
	 * @param array $attributes normalize() を通した属性。
	 * @param int   $page       ページ。
	 * @return WP_Query
	 */
	public static function query( array $attributes, $page ) {
		$meta_query = array(
			'relation'          => 'AND',
			'eventwatch_starts' => array(
				'key'     => Event_Meta::FIRST_START,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => Event_Meta::LAST_END,
				'value'   => Event_Dates::upcoming_boundary(),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		);

		$from = self::parse_date( $attributes['dateFrom'] );
		if ( null !== $from ) {
			$meta_query[] = array(
				'key'     => Event_Meta::LAST_END,
				'value'   => $from->getTimestamp(),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		$to = self::parse_date( $attributes['dateTo'] );
		if ( null !== $to ) {
			$meta_query[] = array(
				'key'     => Event_Meta::FIRST_START,
				'value'   => $to->modify( '+1 day' )->getTimestamp(),
				'compare' => '<',
				'type'    => 'NUMERIC',
			);
		}

		if ( $attributes['prefecture'] > 0 ) {
			$meta_query[] = array(
				'key'   => Event_Meta::PREFECTURE,
				'value' => $attributes['prefecture'],
				'type'  => 'NUMERIC',
			);
		}

		if ( ! empty( $attributes['eventCategory'] ) ) {
			$meta_query[] = array(
				'key'   => Event_Meta::CATEGORY,
				'value' => (int) $attributes['eventCategory'],
				'type'  => 'NUMERIC',
			);
		}

		$tax_query = array();
		foreach ( array(
			Post_Type::TAX_CATEGORY => $attributes['categoryId'],
			Post_Type::TAX_TAG      => $attributes['tagId'],
		) as $taxonomy => $term_id ) {
			if ( $term_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'terms'    => $term_id,
				);
			}
		}

		$order = 'desc' === $attributes['order'] ? 'DESC' : 'ASC';

		// phpcs:disable WordPress.DB.SlowDBQuery -- 判定と絞り込みはメタとタームにしか持てない。件数は MAX_SIZE までに抑えている。
		return new WP_Query(
			array(
				'post_type'           => Post_Type::POST_TYPE,
				// ログイン中でも、下書きや非公開を公開のページに混ぜない。
				'post_status'         => 'publish',
				// メタがイベントの中身そのものなので、パスワードを知らない人に出さない。テンプレートへ渡す前に落とす。
				'has_password'        => false,
				'posts_per_page'      => $attributes['perPage'],
				'paged'               => $page,
				'ignore_sticky_posts' => true,
				'meta_query'          => $meta_query,
				'tax_query'           => $tax_query,
				self::KEYWORD_VAR     => empty( $attributes['keyword'] ) ? array() : array_values( $attributes['keyword'] ),
				'orderby'             => array(
					'eventwatch_starts' => $order,
					'ID'                => $order,
				),
			)
		);
		// phpcs:enable
	}

	/**
	 * キーワードの条件を足す。
	 *
	 * 本体の検索はメタを見ないので、会場名に当てるために自分で組む。語はすべて含むものに当てる。
	 *
	 * @param string   $where WHERE の文。
	 * @param WP_Query $query クエリ。
	 * @return string
	 */
	public static function filter_where( $where, $query ) {
		$terms = $query->get( self::KEYWORD_VAR );
		if ( ! is_array( $terms ) || ! $terms ) {
			return $where;
		}

		global $wpdb;
		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( (string) $term ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- 表の名前は $wpdb の値で、語は prepare を通している。
			$where .= $wpdb->prepare( " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS eventwatch_venue WHERE eventwatch_venue.post_id = {$wpdb->posts}.ID AND eventwatch_venue.meta_key = %s AND eventwatch_venue.meta_value LIKE %s))", $like, $like, Event_Meta::VENUE_NAME, $like );
		}

		return $where;
	}

	/**
	 * テンプレートへ渡す 1 件のデータ。
	 *
	 * @param WP_Post  $post イベント。
	 * @param int|null $from 期間で絞っているときの始まり。
	 * @return array{id: int, title: string, url: string, image: string, date: string, datetime: string, more: string, venue: string, category: string}
	 */
	public static function item_data( WP_Post $post, $from = null ) {
		$summary = Event_Dates::summary( $post->ID, $from );
		$more    = $summary ? $summary['more'] : 0;

		return array(
			'id'       => $post->ID,
			// カード全体がリンクなので、題の中のリンクが入れ子にならないようタグを落とす。
			'title'    => wp_strip_all_tags( get_the_title( $post ) ),
			'url'      => (string) get_permalink( $post ),
			'image'    => self::image_html( $post ),
			'date'     => $summary ? $summary['text'] : '',
			'datetime' => $summary ? $summary['datetime'] : '',
			'more'     => $more > 0
				/* translators: %d: the number of other dates of the event. */
				? sprintf( _n( '+%d more date', '+%d more dates', $more, 'eventwatch' ), $more )
				: '',
			'venue'    => self::venue_text( $post->ID ),
			'category' => self::category_text( $post ),
		);
	}

	/**
	 * 1 件を描く。テーマに同名のテンプレートがあれば、そちらを使う。
	 *
	 * the_post を呼ばない。入れ子のクエリで、固定ページの本文のループを壊さないため。
	 *
	 * @param array   $attributes normalize() を通した属性。
	 * @param WP_Post $post       イベント。
	 * @return string
	 */
	private static function render_item( array $attributes, WP_Post $post ) {
		$name     = self::LAYOUT_LIST === $attributes['layout'] ? 'event-row.php' : 'event-card.php';
		$template = locate_template( self::THEME_DIR . '/' . $name );
		if ( '' === $template ) {
			$template = plugin_dir_path( PLUGIN_FILE ) . 'templates/' . $name;
		}

		ob_start();
		$from = self::parse_date( $attributes['dateFrom'] );
		load_template( $template, false, self::item_data( $post, $from ? $from->getTimestamp() : null ) );
		return (string) ob_get_clean();
	}

	/**
	 * ページ送り。1 ページしかなければ出さない。
	 *
	 * クエリの名前は属性ごとに分かれるので、1 ページに複数置いても互いに動かない。属性まで同じブロックは連動する。
	 *
	 * @param string $param  ページのクエリの名前。
	 * @param string $anchor ブロックの id。移った先で一覧の頭に着地する。
	 * @param int    $page   今のページ。
	 * @param int    $total  ページの数。
	 * @return string
	 */
	private static function render_pagination( $param, $anchor, $page, $total ) {
		if ( $total < 2 ) {
			return '';
		}

		$links = paginate_links(
			array(
				'base'         => add_query_arg( $param, '%#%' ),
				'format'       => '',
				'current'      => $page,
				'total'        => $total,
				'type'         => 'plain',
				'add_fragment' => '#' . $anchor,
				'prev_text'    => __( 'Previous', 'eventwatch' ),
				'next_text'    => __( 'Next', 'eventwatch' ),
			)
		);

		return '<nav class="eventwatch-events__pagination" aria-label="' . esc_attr__( 'Pagination', 'eventwatch' ) . '">' . $links . '</nav>';
	}

	/**
	 * アイキャッチ画像の HTML。無ければ空。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function image_html( WP_Post $post ) {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		if ( ! $thumbnail_id ) {
			return '';
		}

		$alt = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );

		return (string) get_the_post_thumbnail(
			$post,
			'medium_large',
			array(
				'alt'     => '' !== $alt ? $alt : wp_strip_all_tags( get_the_title( $post ) ),
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * 会場の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function venue_text( $post_id ) {
		if ( get_post_meta( $post_id, Event_Meta::ONLINE, true ) ) {
			return __( 'Online', 'eventwatch' );
		}

		$venue = (string) get_post_meta( $post_id, Event_Meta::VENUE_NAME, true );

		return '' !== $venue ? $venue : __( 'Venue to be announced', 'eventwatch' );
	}

	/**
	 * カテゴリの文。サイトのカテゴリがあればそれ、無ければ EventWatch のカテゴリ。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function category_text( WP_Post $post ) {
		$terms = get_the_terms( $post, Post_Type::TAX_CATEGORY );
		if ( is_array( $terms ) && $terms ) {
			return $terms[0]->name;
		}

		$categories = Event_Options::categories();
		$category   = (int) get_post_meta( $post->ID, Event_Meta::CATEGORY, true );

		return isset( $categories[ $category ] ) ? $categories[ $category ] : '';
	}

	/**
	 * `YYYY-MM-DD` をサイトのタイムゾーンの 0 時にする。
	 *
	 * @param mixed $value 値。
	 * @return DateTimeImmutable|null 実在しない日や書式違いは null。
	 */
	public static function parse_date( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		// 2 月 30 日は 3 月 2 日に繰り上がるので、書式で戻して元の文字列と比べる。
		return $parsed && $parsed->format( 'Y-m-d' ) === $value ? $parsed : null;
	}
}
