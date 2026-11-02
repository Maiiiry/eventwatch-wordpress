<?php
/**
 * イベントの一覧の画面の列。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeZone;
use Exception;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 開催日・都道府県の列と、開催日での並べ替えを持つ。
 */
class Admin_Columns {

	const COLUMN_DATE       = 'eventwatch_date';
	const COLUMN_PREFECTURE = 'eventwatch_prefecture';
	const ORDERBY           = 'eventwatch_first_start';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		$type = Post_Type::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'add_columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( self::class, 'add_sortable' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_order' ) );
	}

	/**
	 * タイトルの直後に列を足す。
	 *
	 * @param array<string, string> $columns 列。
	 * @return array<string, string>
	 */
	public static function add_columns( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result[ self::COLUMN_DATE ]       = __( 'Event date', 'eventwatch' );
				$result[ self::COLUMN_PREFECTURE ] = __( 'Prefecture', 'eventwatch' );
			}
		}
		return $result;
	}

	/**
	 * 列の中身を出す。
	 *
	 * @param string $column  列。
	 * @param int    $post_id 投稿の ID。
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN_DATE === $column ) {
			echo esc_html( self::date_text( (int) $post_id ) );
		} elseif ( self::COLUMN_PREFECTURE === $column ) {
			echo esc_html( self::prefecture_text( (int) $post_id ) );
		}
	}

	/**
	 * 並べ替えられる列を足す。
	 *
	 * @param array<string, string> $columns 列。
	 * @return array<string, string>
	 */
	public static function add_sortable( $columns ) {
		$columns[ self::COLUMN_DATE ] = self::ORDERBY;
		return $columns;
	}

	/**
	 * 開催日で並べ替える。
	 *
	 * meta_key で並べると、メタの無い投稿が結果から消える。日程の無い下書きを一覧に残すため、NOT EXISTS と OR にする。
	 *
	 * @param WP_Query $query クエリ。
	 * @return void
	 */
	public static function apply_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::ORDERBY !== $query->get( 'orderby' ) ) {
			return;
		}
		if ( Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理画面の一覧の並べ替えだけで使う。
		$query->set(
			'meta_query',
			array(
				'relation'        => 'OR',
				'eventwatch_date' => array(
					'key'     => Event_Meta::FIRST_START,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => Event_Meta::FIRST_START,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		// phpcs:enable
		$query->set( 'orderby', array( 'eventwatch_date' => 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC' ) );
	}

	/**
	 * 開催日の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function date_text( $post_id ) {
		$rows      = get_post_meta( $post_id, Event_Meta::OCCURRENCES, true );
		$timestamp = get_post_meta( $post_id, Event_Meta::FIRST_START, true );

		if ( ! is_array( $rows ) || ! $rows || '' === $timestamp ) {
			return '—';
		}

		try {
			$zone = new DateTimeZone( (string) get_post_meta( $post_id, Event_Meta::ZONE, true ) );
		} catch ( Exception $e ) {
			$zone = wp_timezone();
		}

		$text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp, $zone );
		$more = count( $rows ) - 1;

		if ( $more > 0 ) {
			/* translators: 1: the first date of the event, 2: the number of other dates. */
			return sprintf( _n( '%1$s (+%2$d more date)', '%1$s (+%2$d more dates)', $more, 'eventwatch' ), $text, $more );
		}

		return (string) $text;
	}

	/**
	 * 都道府県の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function prefecture_text( $post_id ) {
		$prefectures = Event_Options::prefectures();
		$code        = (int) get_post_meta( $post_id, Event_Meta::PREFECTURE, true );

		if ( isset( $prefectures[ $code ] ) ) {
			return $prefectures[ $code ];
		}

		return get_post_meta( $post_id, Event_Meta::ONLINE, true ) ? __( 'Online', 'eventwatch' ) : '—';
	}
}
