<?php
/**
 * カレンダーに追加（iCal と Google カレンダー）。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 時刻は UTC で書く。VTIMEZONE を持たずに、どのカレンダーでも同じ瞬間になる。
 */
class Event_Ical {

	const QUERY_VAR = 'eventwatch_ics';

	/**
	 * 終了が空の組の長さ。カレンダーは長さ 0 だと見えにくい。
	 */
	const DEFAULT_LENGTH = HOUR_IN_SECONDS;

	/**
	 * 1 行の上限のオクテット数（RFC 5545 3.1）。
	 */
	const LINE_LIMIT = 75;

	/**
	 * .ics を配れるか。公開の、パスワードの無い、日程のあるイベントだけ。
	 *
	 * @param WP_Post $post イベント。
	 * @return bool
	 */
	public static function is_downloadable( WP_Post $post ) {
		return Post_Type::POST_TYPE === $post->post_type
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post )
			&& array() !== Event_Dates::occurrences( $post->ID );
	}

	/**
	 * .ics の URL。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function download_url( WP_Post $post ) {
		return add_query_arg( self::QUERY_VAR, '1', (string) get_permalink( $post ) );
	}

	/**
	 * クエリがあれば .ics を返す。配れないイベントは 404 にする。
	 *
	 * @return void
	 */
	public static function maybe_send() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 公開のイベントを読むだけの要求。
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::is_downloadable( $post ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$name = preg_match( '/^[a-z0-9-]+$/', $post->post_name ) ? $post->post_name : 'event-' . $post->ID;

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.ics"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ではなくテキストのカレンダー。build の中で TEXT のエスケープをしている。
		echo self::build( $post );
		exit;
	}

	/**
	 * カレンダーの本文。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function build( WP_Post $post ) {
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url      = (string) get_permalink( $post );
		$title    = Event_Detail::plain_title( $post );
		$location = self::location_text( $post->ID );
		$stamp    = gmdate( 'Ymd\THis\Z', (int) get_post_modified_time( 'U', true, $post ) );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//EventWatch//EventWatch Events//EN',
			'CALSCALE:GREGORIAN',
		);

		foreach ( Event_Dates::occurrences( $post->ID ) as $row ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $post->ID . '-' . $row['start'] . '@' . self::escape( $host );
			$lines[] = 'DTSTAMP:' . $stamp;
			$lines[] = 'DTSTART:' . self::utc( $row['start'] );
			$lines[] = 'DTEND:' . self::utc( self::end_of( $row ) );
			$lines[] = 'SUMMARY:' . self::escape( $title );
			if ( '' !== $location ) {
				$lines[] = 'LOCATION:' . self::escape( $location );
			}
			$lines[] = 'URL:' . $url;
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Google カレンダーの作成画面の URL。カレンダーは 1 回に 1 組しか足せない。
	 *
	 * @param WP_Post                          $post イベント。
	 * @param array{start: int, end: int|null} $row  組。
	 * @return string
	 */
	public static function google_url( WP_Post $post, array $row ) {
		$args = array(
			'action'  => 'TEMPLATE',
			'text'    => rawurlencode( Event_Detail::plain_title( $post ) ),
			'dates'   => self::utc( $row['start'] ) . '/' . self::utc( self::end_of( $row ) ),
			'details' => rawurlencode( (string) get_permalink( $post ) ),
		);

		$location = self::location_text( $post->ID );
		if ( '' !== $location ) {
			$args['location'] = rawurlencode( $location );
		}

		return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
	}

	/**
	 * 組の終わり。終了が空か開始と同じなら、既定の長さにする。
	 *
	 * @param array{start: int, end: int|null} $row 組。
	 * @return int
	 */
	private static function end_of( array $row ) {
		// DTEND は DTSTART より後でなければならない（RFC 5545 3.8.2.2）。終了が開始と同じ組も保存できる。
		return null !== $row['end'] && $row['end'] > $row['start'] ? $row['end'] : $row['start'] + self::DEFAULT_LENGTH;
	}

	/**
	 * 場所の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function location_text( $post_id ) {
		if ( get_post_meta( $post_id, Event_Meta::ONLINE, true ) ) {
			return __( 'Online', 'eventwatch' );
		}

		$parts = array(
			(string) get_post_meta( $post_id, Event_Meta::VENUE_NAME, true ),
			(string) get_post_meta( $post_id, Event_Meta::VENUE_ADDRESS, true ),
		);

		return implode( ' ', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * UTC の時刻の文字列。
	 *
	 * @param int $timestamp UTC の整数。
	 * @return string
	 */
	private static function utc( $timestamp ) {
		return gmdate( 'Ymd\THis\Z', $timestamp );
	}

	/**
	 * TEXT のエスケープ（RFC 5545 3.3.11）。
	 *
	 * @param string $text 文。
	 * @return string
	 */
	public static function escape( $text ) {
		return str_replace(
			array( '\\', ';', ',', "\r\n", "\n", "\r" ),
			array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ),
			$text
		);
	}

	/**
	 * 1 行を 75 オクテットで折り返す。マルチバイトの途中で切らない。
	 *
	 * @param string $line 行。
	 * @return string
	 */
	public static function fold( $line ) {
		if ( strlen( $line ) <= self::LINE_LIMIT ) {
			return $line;
		}

		$folded = '';
		$length = 0;
		foreach ( (array) preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$size = strlen( (string) $char );
			if ( $length + $size > self::LINE_LIMIT ) {
				$folded .= "\r\n ";
				// 続きの行の先頭の空白も 1 オクテットに数える。
				$length = 1;
			}
			$folded .= $char;
			$length += $size;
		}

		return $folded;
	}
}
