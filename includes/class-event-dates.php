<?php
/**
 * イベントの日程の表示。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存した壁時計の時刻とタイムゾーンから、表示の文を作る。
 *
 * サイトのタイムゾーンを後で変えても、イベントの時刻はずれない。
 */
class Event_Dates {

	/**
	 * 「開催予定」の境目。サイトのタイムゾーンの今日の 0 時。
	 *
	 * 終了の当日はまだ開催予定として扱う。終了が空の組は開始の時刻で終わった扱いになるので、時刻で切ると始まった瞬間に消える。
	 *
	 * @return int
	 */
	public static function upcoming_boundary() {
		return ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
	}

	/**
	 * 一覧に出す日時。まだ終わっていない最初の組と、その後の組の数。
	 *
	 * すべて終わっていれば最後の組を返す。
	 *
	 * @param int      $post_id 投稿の ID。
	 * @param int|null $from    期間で絞っているときの始まり。これより前に終わる組を飛ばす。
	 * @return array{text: string, datetime: string, more: int}|null 日程が無ければ null。
	 */
	public static function summary( $post_id, $from = null ) {
		$rows = get_post_meta( $post_id, Event_Meta::OCCURRENCES, true );
		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}

		$rows     = array_values( $rows );
		$zone     = self::zone( $post_id );
		$boundary = max( self::upcoming_boundary(), (int) $from );
		$index    = count( $rows ) - 1;

		foreach ( $rows as $i => $row ) {
			$end = Event_Meta::to_utc( '' !== $row['end'] ? $row['end'] : $row['start'], $zone->getName() );
			if ( null !== $end && $end >= $boundary ) {
				$index = $i;
				break;
			}
		}

		$start = Event_Meta::to_utc( $rows[ $index ]['start'], $zone->getName() );
		if ( null === $start ) {
			return null;
		}

		return array(
			'text'     => self::format_row( $rows[ $index ], $start, $zone ),
			'datetime' => wp_date( 'c', $start, $zone ),
			'more'     => count( $rows ) - $index - 1,
		);
	}

	/**
	 * 範囲に重なる組があるか。
	 *
	 * @param int $post_id 投稿の ID。
	 * @param int $from    範囲の始まりの UTC の整数。
	 * @param int $until   範囲の終わりの UTC の整数。この時刻は含まない。
	 * @return bool
	 */
	public static function has_occurrence_between( $post_id, $from, $until ) {
		$rows = get_post_meta( $post_id, Event_Meta::OCCURRENCES, true );
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$zone = self::zone( $post_id )->getName();
		foreach ( $rows as $row ) {
			$start = Event_Meta::to_utc( $row['start'], $zone );
			$end   = Event_Meta::to_utc( '' !== $row['end'] ? $row['end'] : $row['start'], $zone );
			if ( null !== $start && null !== $end && $start < $until && $end >= $from ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 組の文。同じ日に終わる組は開始だけ、日をまたぐ組は終わりの日まで出す。
	 *
	 * @param array{start: string, end: string} $row   組。
	 * @param int                               $start 開始の UTC の整数。
	 * @param DateTimeZone                      $zone  イベントのタイムゾーン。
	 * @return string
	 */
	private static function format_row( array $row, $start, DateTimeZone $zone ) {
		$start_text = wp_date( self::start_format( $start, $zone ), $start, $zone );

		if ( '' === $row['end'] || substr( $row['end'], 0, 10 ) === substr( $row['start'], 0, 10 ) ) {
			return (string) $start_text;
		}

		$end = Event_Meta::to_utc( $row['end'], $zone->getName() );
		if ( null === $end ) {
			return (string) $start_text;
		}

		return sprintf(
			/* translators: 1: when the event starts, 2: the day the event ends. */
			_x( '%1$s – %2$s', 'event date range', 'eventwatch' ),
			$start_text,
			wp_date( self::end_format( $end, $zone ), $end, $zone )
		);
	}

	/**
	 * 開始の書式。年を常に出す言語と、今年でないときだけ出す言語があるので、2 つの文字列に分ける。
	 *
	 * @param int          $timestamp UTC の整数。
	 * @param DateTimeZone $zone      イベントのタイムゾーン。
	 * @return string
	 */
	private static function start_format( $timestamp, DateTimeZone $zone ) {
		if ( self::is_this_year( $timestamp, $zone ) ) {
			/* translators: PHP date format for the start of an event in the current year. */
			return _x( 'D, M j, Y, g:i A', 'event start this year', 'eventwatch' );
		}
		/* translators: PHP date format for the start of an event in another year. */
		return _x( 'D, M j, Y, g:i A', 'event start in another year', 'eventwatch' );
	}

	/**
	 * 終わりの日の書式。
	 *
	 * @param int          $timestamp UTC の整数。
	 * @param DateTimeZone $zone      イベントのタイムゾーン。
	 * @return string
	 */
	private static function end_format( $timestamp, DateTimeZone $zone ) {
		if ( self::is_this_year( $timestamp, $zone ) ) {
			/* translators: PHP date format for the last day of an event in the current year. */
			return _x( 'D, M j, Y', 'event end this year', 'eventwatch' );
		}
		/* translators: PHP date format for the last day of an event in another year. */
		return _x( 'D, M j, Y', 'event end in another year', 'eventwatch' );
	}

	/**
	 * イベントのタイムゾーンで今年か。
	 *
	 * @param int          $timestamp UTC の整数。
	 * @param DateTimeZone $zone      イベントのタイムゾーン。
	 * @return bool
	 */
	private static function is_this_year( $timestamp, DateTimeZone $zone ) {
		return wp_date( 'Y', $timestamp, $zone ) === wp_date( 'Y', time(), $zone );
	}

	/**
	 * イベントのタイムゾーン。読めなければサイトのもの。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return DateTimeZone
	 */
	private static function zone( $post_id ) {
		try {
			return new DateTimeZone( (string) get_post_meta( $post_id, Event_Meta::ZONE, true ) );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}
}
