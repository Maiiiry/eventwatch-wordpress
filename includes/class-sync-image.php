<?php
/**
 * アイキャッチ画像を EventWatch へ上げる。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 送る投稿ごとに、`imageUploadId` に入れる ID を決める。
 *
 * 上げられなくても本文の同期は止めない。前に送った ID があればそれを送り、理由を残す。
 * `imageUploadId` は全置換で、省くと画像が外れる。1 つの投入画像は 1 か所にしか結び付かないので、記録は投稿ごとに持つ。
 */
class Sync_Image {

	const RECORD = '_eventwatch_sync_image';
	const ISSUE  = '_eventwatch_sync_image_issue';

	const HOLD_OPTION = 'eventwatch_sync_image_hold_until';

	const UPLOAD_PATH = '/api/v1.0/org-api/images';
	const PURPOSE     = 'ownedEventImage';

	/**
	 * EventWatch の上限は 10 MiB で、要求の全体にも同じ上限がある。multipart の枠の分を残す。
	 */
	const MAX_BYTES = 10484736;

	/**
	 * 結び付いていない投入画像は EventWatch で 24 時間後に回収される。余裕を持って使うのをやめる。
	 */
	const UNATTACHED_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * 投入量の上限に当たった後に投入を止める秒数。上限は 24 時間の窓で数えるので、いつ空くかは応答から分からない。
	 */
	const HOLD_SECONDS = HOUR_IN_SECONDS;

	const REQUEUE_MAX = 30;

	/**
	 * 元のファイルが合わないときに試す、本体が作る縮小版。切り抜かれる大きさは含めない。
	 */
	const FALLBACK_SIZES = array( '2048x2048', '1536x1536', 'large' );

	const ISSUE_FORMAT     = 'format';
	const ISSUE_TOO_LARGE  = 'tooLarge';
	const ISSUE_UNREADABLE = 'unreadable';
	const ISSUE_REJECTED   = 'rejected';
	const ISSUE_TEMPORARY  = 'temporary';
	const ISSUE_QUOTA      = 'quota';

	/**
	 * 先頭バイトで形式を見分ける。EventWatch は同じ判定をするので、申告の形式を信じずに送る前に分かる。
	 */
	const FORMATS = array(
		'image/jpeg' => array(
			'extension' => 'jpg',
			'prefix'    => "\xFF\xD8\xFF",
		),
		'image/png'  => array(
			'extension' => 'png',
			'prefix'    => "\x89PNG\r\n\x1A\n",
		),
		'image/webp' => array(
			'extension' => 'webp',
			'prefix'    => 'RIFF',
			'at_8'      => 'WEBP',
		),
	);

	/**
	 * 送る ID を決める。投入が要れば、許されるときだけ上げる。
	 *
	 * @param int  $post_id    投稿の ID。
	 * @param bool $can_upload 上げてよいか。保存の時点では公開の操作を待たせない。
	 * @return array{id: string, fresh: bool, later: bool, requests: int}|WP_Error WP_Error は本文の送信と同じ扱いにする失敗（待つ・止める）。
	 */
	public static function resolve( $post_id, $can_upload ) {
		delete_post_meta( $post_id, self::ISSUE );

		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			delete_post_meta( $post_id, self::RECORD );
			return self::result();
		}

		$saved    = get_post_meta( $post_id, self::RECORD, true );
		$previous = is_array( $saved ) && isset( $saved['attachedId'] ) ? $saved['attachedId'] : '';
		$record   = self::usable_record( $post_id );
		$file     = self::choose_file( $thumbnail_id );

		if ( is_string( $file ) ) {
			return self::keep_previous( $post_id, $previous, $file );
		}

		$hash = (string) hash_file( 'sha256', $file['path'] );
		if ( $record && hash_equals( $record['hash'], $hash ) ) {
			return self::result( $record['id'] );
		}

		if ( (int) get_option( self::HOLD_OPTION, 0 ) > time() ) {
			return self::keep_previous( $post_id, $previous, self::ISSUE_QUOTA );
		}

		if ( ! $can_upload ) {
			return array_merge( self::result(), array( 'later' => true ) );
		}

		return self::upload( $post_id, $file, $hash, $previous );
	}

	/**
	 * `PUT` の応答で、投入画像が結び付いたかを確かめる。古い要求として反映されなかった応答には載らない。
	 *
	 * @param int   $post_id 投稿の ID。
	 * @param array $event   応答の投稿。
	 * @return void
	 */
	public static function after_put( $post_id, array $event ) {
		$record = get_post_meta( $post_id, self::RECORD, true );

		if ( is_array( $record ) && isset( $event['imageUploadId'] ) && $event['imageUploadId'] === $record['id'] ) {
			$record['attached']   = true;
			$record['attachedId'] = $record['id'];
			update_post_meta( $post_id, self::RECORD, $record );
		}
	}

	/**
	 * 取り込んだ画像を、結び付いている投入画像として記録する。次の更新で上げ直さない。
	 *
	 * 比べるのは送るときに選ぶファイルなので、同じ選び方をする。
	 *
	 * @param int    $post_id       投稿の ID。
	 * @param int    $attachment_id 添付の ID。
	 * @param string $upload_id     EventWatch の投入画像の ID。
	 * @return void
	 */
	public static function record_imported( $post_id, $attachment_id, $upload_id ) {
		$file = self::choose_file( $attachment_id );
		if ( is_string( $file ) || '' === $upload_id ) {
			return;
		}

		update_post_meta(
			$post_id,
			self::RECORD,
			array(
				'hash'       => (string) hash_file( 'sha256', $file['path'] ),
				'id'         => $upload_id,
				'at'         => time(),
				'attached'   => true,
				'attachedId' => $upload_id,
			)
		);
	}

	/**
	 * 持っている ID が使えないと分かったので忘れる。次の送信で上げ直す。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function forget( $post_id ) {
		delete_post_meta( $post_id, self::RECORD );
	}

	/**
	 * 時間が経てば通るかもしれない失敗の投稿を、送信の待ち行列へ戻す。定期の取り込みの cron から呼ぶ。
	 *
	 * 積み直すと理由のメタが作り直されて行が後ろへ回るので、失敗し続ける投稿がほかを塞がない。
	 *
	 * @return void
	 */
	public static function requeue() {
		global $wpdb;

		if ( (int) get_option( self::HOLD_OPTION, 0 ) > time() || self::is_stopped() ) {
			return;
		}

		// 送信待ちの投稿は、その送り直しの中で上げ直す。積み直すと送り直しの間隔が戻り、断られた本文の失敗の記録も消える。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 古い順に数を絞る。結果を覚えると待ち行列を読み違える。
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.post_id FROM {$wpdb->postmeta} i WHERE i.meta_key = %s AND i.meta_value IN (%s, %s) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} o WHERE o.post_id = i.post_id AND o.meta_key IN (%s, %s) ) ORDER BY i.meta_id LIMIT %d",
				self::ISSUE,
				self::ISSUE_TEMPORARY,
				self::ISSUE_QUOTA,
				Sync_Queue::PENDING,
				Sync_Queue::ERROR,
				self::REQUEUE_MAX
			)
		);

		if ( $post_ids ) {
			Sync_Queue::mark_many( array_map( 'intval', $post_ids ) );
		}
	}

	/**
	 * 時間では直らず、送信を止めている状態か。積み直すと止めた cron を張り直してしまう。
	 *
	 * @return bool
	 */
	private static function is_stopped() {
		return '' === Connection::api_key()
			|| in_array( Connection::state()['status'], array( Connection::UNAUTHORIZED, Connection::SUSPENDED, Connection::FORBIDDEN ), true );
	}

	/**
	 * 上げる。
	 *
	 * @param int    $post_id  投稿の ID。
	 * @param array  $file     ファイル。
	 * @param string $hash     ファイルの同一性。
	 * @param string $previous 前の ID。
	 * @return array|WP_Error
	 */
	private static function upload( $post_id, array $file, $hash, $previous ) {
		// 読み込みと multipart の組み立てで 10 MiB を 2 回持つ。cron の要求の既定の上限では足りないことがあり、超えると捕まえられない。
		wp_raise_memory_limit( 'admin' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- サイトのアップロードの中のファイルを読む。
		$content = file_get_contents( $file['path'] );
		if ( false === $content ) {
			return self::keep_previous( $post_id, $previous, self::ISSUE_UNREADABLE );
		}

		$result = ( new Api_Client() )->request(
			'POST',
			self::UPLOAD_PATH,
			array(
				'multipart' => array(
					'fields' => array( 'purpose' => self::PURPOSE ),
					'file'   => array(
						'name'     => 'file',
						// サイトのファイル名を送らない。形式は EventWatch が中身で見る。
						'filename' => 'image.' . self::FORMATS[ $file['type'] ]['extension'],
						'type'     => $file['type'],
						'content'  => $content,
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( self::is_for_the_queue( $result ) ) {
				return $result;
			}
			return array_merge( self::keep_previous( $post_id, $previous, self::issue_for( $result ) ), array( 'requests' => 1 ) );
		}

		$id = is_array( $result['body'] ) && isset( $result['body']['id'] ) ? (string) $result['body']['id'] : '';
		if ( ! wp_is_uuid( $id ) ) {
			return array_merge( self::keep_previous( $post_id, $previous, self::ISSUE_TEMPORARY ), array( 'requests' => 1 ) );
		}

		update_post_meta(
			$post_id,
			self::RECORD,
			array(
				'hash'       => $hash,
				'id'         => $id,
				'at'         => time(),
				'attached'   => false,
				// 本文が届くまでは、EventWatch の画像は前のまま。上げられないときに送る ID として残す。
				'attachedId' => $previous,
			)
		);

		return array_merge(
			self::result( $id ),
			array(
				'fresh'    => true,
				'requests' => 1,
			)
		);
	}

	/**
	 * 本文の送信と同じ扱いにする失敗か。EventWatch が待ちを指示したら、本文も送らない。
	 *
	 * @param WP_Error $error 失敗。
	 * @return bool
	 */
	private static function is_for_the_queue( WP_Error $error ) {
		if ( Api_Client::RATE_LIMITED === $error->get_error_code() || Sync_Queue::is_halting( $error ) ) {
			return true;
		}

		$data = $error->get_error_data();

		return Api_Client::TEMPORARY === $error->get_error_code() && is_array( $data ) && ! empty( $data['retry_after'] );
	}

	/**
	 * 投入の失敗の種類を決める。
	 *
	 * @param WP_Error $error 失敗。
	 * @return string
	 */
	private static function issue_for( WP_Error $error ) {
		if ( Api_Client::TEMPORARY === $error->get_error_code() ) {
			return self::ISSUE_TEMPORARY;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		// 424 は EventWatch の中の画像の処理の障害で、ファイルのせいではない。
		if ( 424 === $status ) {
			return self::ISSUE_TEMPORARY;
		}

		if ( 409 === $status ) {
			update_option( self::HOLD_OPTION, time() + self::HOLD_SECONDS, false );
			return self::ISSUE_QUOTA;
		}

		return 413 === $status ? self::ISSUE_TOO_LARGE : self::ISSUE_REJECTED;
	}

	/**
	 * 理由を残し、前の ID のまま送る。
	 *
	 * @param int    $post_id  投稿の ID。
	 * @param string $previous 前の ID。
	 * @param string $issue    理由。
	 * @return array
	 */
	private static function keep_previous( $post_id, $previous, $issue ) {
		update_post_meta( $post_id, self::ISSUE, $issue );

		return self::result( $previous );
	}

	/**
	 * まだ使える記録。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return array|null
	 */
	private static function usable_record( $post_id ) {
		$record = get_post_meta( $post_id, self::RECORD, true );

		if ( ! is_array( $record ) ) {
			return null;
		}

		return $record['attached'] || time() - (int) $record['at'] < self::UNATTACHED_TTL ? $record : null;
	}

	/**
	 * 送るファイルを選ぶ。元のファイルから順に、形式と大きさの合う最初のもの。
	 *
	 * @param int $attachment_id 添付の ID。
	 * @return array{path: string, type: string}|string 合わなければ理由。
	 */
	private static function choose_file( $attachment_id ) {
		$original = get_attached_file( $attachment_id );
		if ( ! is_string( $original ) || '' === $original ) {
			return self::ISSUE_UNREADABLE;
		}

		// 本体は PDF などもアイキャッチ画像に設定でき、縮小版を持つ。掲載の画像として送らない。
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return self::ISSUE_FORMAT;
		}

		$paths = array( $original );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		foreach ( self::FALLBACK_SIZES as $size ) {
			if ( is_array( $meta ) && isset( $meta['sizes'][ $size ]['file'] ) ) {
				$paths[] = path_join( dirname( $original ), $meta['sizes'][ $size ]['file'] );
			}
		}

		$readable  = false;
		$too_large = false;
		foreach ( $paths as $path ) {
			$type = is_readable( $path ) ? self::type_of( $path ) : null;
			if ( false === $type ) {
				$readable = true;
			}
			if ( ! is_string( $type ) ) {
				continue;
			}

			$readable = true;
			if ( (int) filesize( $path ) > self::MAX_BYTES ) {
				$too_large = true;
				continue;
			}

			return array(
				'path' => $path,
				'type' => $type,
			);
		}

		if ( ! $readable ) {
			return self::ISSUE_UNREADABLE;
		}

		return $too_large ? self::ISSUE_TOO_LARGE : self::ISSUE_FORMAT;
	}

	/**
	 * ファイルの形式。
	 *
	 * @param string $path パス。
	 * @return string|false|null 受け付ける形式、受け付けない形式なら false、読めなければ null。
	 */
	private static function type_of( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- サイトのアップロードの中のファイルの先頭を読む。
		$head = file_get_contents( $path, false, null, 0, 12 );
		if ( false === $head ) {
			return null;
		}

		foreach ( self::FORMATS as $type => $format ) {
			if ( 0 === strpos( $head, $format['prefix'] ) && ( ! isset( $format['at_8'] ) || substr( $head, 8, 4 ) === $format['at_8'] ) ) {
				return $type;
			}
		}

		return false;
	}

	/**
	 * 戻りの形。
	 *
	 * @param string $id 送る ID。無ければ空。
	 * @return array{id: string, fresh: bool, later: bool, requests: int}
	 */
	private static function result( $id = '' ) {
		return array(
			'id'       => $id,
			'fresh'    => false,
			'later'    => false,
			'requests' => 0,
		);
	}
}
