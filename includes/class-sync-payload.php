<?php
/**
 * EventWatch へ送る内容を、投稿から組み立てる。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 欄の対応は EventWatch の主催者の投稿に揃える。EventWatch が 400 にする内容は送らず、理由を返す。
 */
class Sync_Payload {

	const MAX_TITLE       = 256;
	const MAX_DESCRIPTION = 10000;

	const BLOCK_TITLE_EMPTY      = 'titleEmpty';
	const BLOCK_TITLE_TOO_LONG   = 'titleTooLong';
	const BLOCK_DESCRIPTION_LONG = 'descriptionTooLong';
	const BLOCK_CATEGORY         = 'category';
	const BLOCK_PREFECTURE       = 'prefecture';
	const BLOCK_OCCURRENCES      = 'occurrences';
	const BLOCK_NOTE_TOO_LONG    = 'noteTooLong';
	const BLOCK_VENUE_TOO_LONG   = 'venueTooLong';
	const BLOCK_VENUE_INCOMPLETE = 'venueIncomplete';
	const BLOCK_URL_TOO_LONG     = 'urlTooLong';

	/**
	 * 予約の公開の時刻を持つメタ。本体の予約の公開は post_modified を進めないので、これで補う。
	 */
	const PUBLISHED_AT_META = '_eventwatch_sync_published_at';

	/**
	 * 送る本文を組み立てる。
	 *
	 * @param WP_Post $post 投稿。
	 * @return array{body: array, blocks: array<int, array{code: string, length?: int}>} blocks が空でなければ送らない。
	 */
	public static function build( WP_Post $post ) {
		$title       = self::plain_title( $post );
		$description = Markdown_Converter::from_post_content( $post->post_content );
		$occurrences = get_post_meta( $post->ID, Event_Meta::OCCURRENCES, true );
		$occurrences = is_array( $occurrences ) ? $occurrences : array();
		$category    = (int) get_post_meta( $post->ID, Event_Meta::CATEGORY, true );
		$prefecture  = (int) get_post_meta( $post->ID, Event_Meta::PREFECTURE, true );
		$online      = (bool) get_post_meta( $post->ID, Event_Meta::ONLINE, true );
		$url         = (string) get_post_meta( $post->ID, Event_Meta::URL, true );
		$zone        = (string) get_post_meta( $post->ID, Event_Meta::ZONE, true );

		$body = array(
			'title'                 => $title,
			'description'           => $description,
			'occurrenceRanges'      => array_map(
				static function ( $row ) {
					return array(
						'startAt' => $row['start'],
						'closeAt' => isset( $row['end'] ) ? $row['end'] : '',
					);
				},
				array_values( $occurrences )
			),
			'holdingPeriodNote'     => (string) get_post_meta( $post->ID, Event_Meta::SCHEDULE_NOTE, true ),
			'zoneId'                => '' !== $zone ? $zone : wp_timezone_string(),
			'category'              => $category,
			'prefectureCode'        => $prefecture,
			'online'                => $online,
			'venueName'             => (string) get_post_meta( $post->ID, Event_Meta::VENUE_NAME, true ),
			'venueAddress'          => (string) get_post_meta( $post->ID, Event_Meta::VENUE_ADDRESS, true ),
			'publisherEventPageUrl' => '' !== $url ? $url : (string) get_permalink( $post ),
			'sourceUpdatedAt'       => self::source_updated_at( $post ),
		);

		return array(
			'body'   => $body,
			'blocks' => self::blocks( $title, $description, $body ),
		);
	}

	/**
	 * 本文に画像の ID を足す。無ければ足さない（EventWatch では画像が外れる）。
	 *
	 * @param array  $body 送る本文。
	 * @param string $id   投入画像の ID。
	 * @return array
	 */
	public static function with_image( array $body, $id ) {
		if ( '' !== $id ) {
			$body['imageUploadId'] = $id;
		}

		return $body;
	}

	/**
	 * 送信元の更新日時。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string ISO 8601。オフセット付き。
	 */
	public static function source_updated_at( WP_Post $post ) {
		$modified  = '0000-00-00 00:00:00' === $post->post_modified_gmt ? 0 : (int) strtotime( $post->post_modified_gmt . ' UTC' );
		$published = (int) get_post_meta( $post->ID, self::PUBLISHED_AT_META, true );

		return gmdate( 'Y-m-d\TH:i:s+00:00', max( $modified, $published ) );
	}

	/**
	 * EventWatch の上限の数え方（UTF-16 の単位）での長さ。絵文字は 2 と数える。
	 *
	 * @param string $text 文字列。
	 * @return int
	 */
	public static function server_length( $text ) {
		$characters = preg_match_all( '/./su', $text );

		// 不正な UTF-8 は読めないので、上限の判定を甘くしないようバイト数で数える。
		return false === $characters ? strlen( $text ) : $characters + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * 題を、タグと実体参照の無い形にする。権限の無い人の保存では & が &amp; で入っている。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string
	 */
	private static function plain_title( WP_Post $post ) {
		return trim( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * 送れない理由を全部集める。
	 *
	 * @param string $title       題。
	 * @param string $description 本文。
	 * @param array  $body        送る本文。
	 * @return array<int, array{code: string, length?: int}>
	 */
	private static function blocks( $title, $description, array $body ) {
		$blocks = array();

		if ( '' === $title ) {
			$blocks[] = array( 'code' => self::BLOCK_TITLE_EMPTY );
		} elseif ( self::server_length( $title ) > self::MAX_TITLE ) {
			$blocks[] = array( 'code' => self::BLOCK_TITLE_TOO_LONG );
		}

		if ( ! $body['occurrenceRanges'] ) {
			$blocks[] = array( 'code' => self::BLOCK_OCCURRENCES );
		}

		if ( 0 === $body['category'] ) {
			$blocks[] = array( 'code' => self::BLOCK_CATEGORY );
		}

		if ( ! $body['online'] && 0 === $body['prefectureCode'] ) {
			$blocks[] = array( 'code' => self::BLOCK_PREFECTURE );
		}

		if ( self::server_length( $body['holdingPeriodNote'] ) > Event_Meta::MAX_NOTE ) {
			$blocks[] = array( 'code' => self::BLOCK_NOTE_TOO_LONG );
		}

		$name    = trim( $body['venueName'] );
		$address = trim( $body['venueAddress'] );
		if ( ( '' === $name ) !== ( '' === $address ) ) {
			$blocks[] = array( 'code' => self::BLOCK_VENUE_INCOMPLETE );
		}
		if ( self::server_length( $body['venueName'] ) > Event_Meta::MAX_VENUE_NAME || self::server_length( $body['venueAddress'] ) > Event_Meta::MAX_ADDRESS ) {
			$blocks[] = array( 'code' => self::BLOCK_VENUE_TOO_LONG );
		}

		if ( self::server_length( $body['publisherEventPageUrl'] ) > Event_Meta::MAX_URL ) {
			$blocks[] = array( 'code' => self::BLOCK_URL_TOO_LONG );
		}

		$length = self::server_length( $description );
		if ( $length > self::MAX_DESCRIPTION ) {
			$blocks[] = array(
				'code'   => self::BLOCK_DESCRIPTION_LONG,
				'length' => $length,
			);
		}

		return $blocks;
	}
}
