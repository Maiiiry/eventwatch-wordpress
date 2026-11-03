<?php
/**
 * アイキャッチ画像の送信のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions -- テストのファイルを作って消す。

use EventWatch\Connection;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Image;
use EventWatch\Sync_Pull;
use EventWatch\Sync_Queue;
use EventWatch\Sync_Rest;
use WP_Error;
use WP_UnitTestCase;

/**
 * Sync_Image のテスト。HTTP は pre_http_request で返す。
 */
class Sync_Image_Test extends WP_UnitTestCase {

	const KEY = 'ewk_0123456789012345678901234567890123456789012';

	const ID_A = '0f2b0000-0000-4000-8000-00000000000a';
	const ID_B = '0f2b0000-0000-4000-8000-00000000000b';

	const JPEG = "\xFF\xD8\xFF\xE0";
	const PNG  = "\x89PNG\r\n\x1A\n";
	const WEBP = "RIFF\x00\x00\x00\x00WEBP";
	const GIF  = 'GIF89a';

	/**
	 * 受けた要求。
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * 投入への次の応答。空なら ID_A で 201。
	 *
	 * @var array
	 */
	private $upload_responses = array();

	/**
	 * 投稿の送信への次の応答。空なら送った ID を返す 200。
	 *
	 * @var array
	 */
	private $put_responses = array();

	/**
	 * 作ったファイル。
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * テストごとにメタを登録し、キーを入れて HTTP を差し替える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		update_option( Connection::KEY_OPTION, self::KEY );
		Site_Identity::use_as_moved();
		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
		$this->requests         = array();
		$this->upload_responses = array();
		$this->put_responses    = array();
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * 差し替えを戻す。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		Sync_Queue::forget_all();
		delete_option( Sync_Queue::LOCK_OPTION );
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * 要求を記録して応答を返す。
	 *
	 * @param mixed  $preempt 本体の値。
	 * @param array  $args    要求。
	 * @param string $url     URL。
	 * @return array|WP_Error
	 */
	public function answer( $preempt, $args, $url ) {
		$path      = substr( $url, strlen( 'https://api.eventwatch.app' ) );
		$is_upload = Sync_Image::UPLOAD_PATH === $path;

		$this->requests[] = array(
			'method'  => $args['method'],
			'path'    => $path,
			'raw'     => isset( $args['body'] ) ? $args['body'] : '',
			'body'    => ! $is_upload && isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'headers' => $args['headers'],
			'timeout' => $args['timeout'],
		);

		if ( $is_upload ) {
			return $this->upload_responses ? array_shift( $this->upload_responses ) : $this->response( 201, array( 'id' => self::ID_A ) );
		}

		if ( $this->put_responses ) {
			return array_shift( $this->put_responses );
		}

		$sent = json_decode( $args['body'], true );

		return $this->response(
			200,
			array(
				'applied' => true,
				'event'   => array(
					'status'        => 'pendingReview',
					'visible'       => false,
					'slug'          => 'abc',
					'imageUploadId' => isset( $sent['imageUploadId'] ) ? $sent['imageUploadId'] : null,
				),
			)
		);
	}

	/**
	 * 応答を作る。
	 *
	 * @param int        $status  ステータス。
	 * @param array|null $body    本文。
	 * @param array      $headers ヘッダー。
	 * @return array
	 */
	private function response( $status, $body = null, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * アップロードの中にファイルを作る。
	 *
	 * @param string $name    名前。
	 * @param string $content 中身。
	 * @return string パス。
	 */
	private function file( $name, $content ) {
		$path = trailingslashit( wp_upload_dir()['basedir'] ) . $name;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $content );
		$this->files[] = $path;

		return $path;
	}

	/**
	 * 添付を作る。
	 *
	 * @param string $content 中身。
	 * @param array  $sizes   縮小版（大きさの名前 => 中身）。
	 * @return int
	 */
	private function attachment( $content, array $sizes = array() ) {
		$name = 'ew-test-' . wp_generate_password( 8, false ) . '.jpg';
		$path = $this->file( $name, $content );
		$id   = self::factory()->attachment->create_object(
			array(
				'file'           => $path,
				'post_mime_type' => 'image/jpeg',
			)
		);

		$meta = array( 'sizes' => array() );
		foreach ( $sizes as $size => $body ) {
			$file = $size . '-' . $name;
			$this->file( $file, $body );
			$meta['sizes'][ $size ] = array(
				'file'      => $file,
				'width'     => 100,
				'height'    => 100,
				'mime-type' => 'image/jpeg',
			);
		}
		wp_update_attachment_metadata( $id, $meta );

		return $id;
	}

	/**
	 * 公開のイベントを作り、画像を設定して印を積む。
	 *
	 * @param int $attachment 添付の ID。0 なら画像なし。
	 * @return int
	 */
	private function event( $attachment = 0 ) {
		$id = wp_insert_post(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => '題',
				'post_content' => '<p>本文</p>',
			)
		);
		update_post_meta(
			$id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => '2026-12-01 10:00',
					'end'   => '',
				),
			)
		);
		update_post_meta( $id, Event_Meta::CATEGORY, 3 );
		update_post_meta( $id, Event_Meta::PREFECTURE, 13 );
		if ( $attachment ) {
			set_post_thumbnail( $id, $attachment );
		}
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		return $id;
	}

	/**
	 * Cron の 1 回を流す。
	 *
	 * @return void
	 */
	private function run_queue() {
		Sync_Queue::run();
	}

	/**
	 * 投入の要求。
	 *
	 * @return array
	 */
	private function uploads() {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return Sync_Image::UPLOAD_PATH === $request['path'];
				}
			)
		);
	}

	/**
	 * 投稿の送信の要求。
	 *
	 * @return array
	 */
	private function puts() {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 'PUT' === $request['method'];
				}
			)
		);
	}

	/**
	 * 最後の PUT の本文。
	 *
	 * @return array
	 */
	private function last_put() {
		$puts = $this->puts();

		return end( $puts )['body'];
	}

	/**
	 * 投入は multipart で、サイトのファイル名と URL を送らない。
	 */
	public function test_upload_sends_the_file_as_multipart_without_the_site_file_name() {
		$attachment = $this->attachment( self::JPEG . 'body' );
		$this->event( $attachment );
		$this->run_queue();

		$uploads = $this->uploads();
		$this->assertCount( 1, $uploads );
		$this->assertSame( 'POST', $uploads[0]['method'] );
		$this->assertSame( 30.0, (float) $uploads[0]['timeout'] );
		$this->assertSame( self::KEY, $uploads[0]['headers']['X-EW-Api-Key'] );

		$this->assertSame( 1, preg_match( '/^multipart\/form-data; boundary=(\S+)$/', $uploads[0]['headers']['Content-Type'], $matches ) );
		$boundary = $matches[1];
		$raw      = $uploads[0]['raw'];
		$this->assertStringStartsWith( "--{$boundary}\r\n", $raw );
		$this->assertStringEndsWith( "\r\n--{$boundary}--\r\n", $raw );
		$this->assertStringContainsString( "Content-Disposition: form-data; name=\"purpose\"\r\n\r\nownedEventImage\r\n", $raw );
		$this->assertStringContainsString( "Content-Disposition: form-data; name=\"file\"; filename=\"image.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n" . self::JPEG . "body\r\n", $raw );
		$this->assertStringNotContainsString( 'ew-test-', $raw );
		$this->assertStringNotContainsString( self::KEY, $raw );
	}

	/**
	 * 形式は先頭バイトで見て、受け付ける形式ごとに名前と型を付ける。
	 *
	 * @dataProvider accepted_formats
	 *
	 * @param string $content  中身。
	 * @param string $filename 送る名前。
	 * @param string $type     送る型。
	 */
	public function test_each_accepted_format_is_uploaded( $content, $filename, $type ) {
		$this->event( $this->attachment( $content ) );
		$this->run_queue();

		$uploads = $this->uploads();
		$this->assertCount( 1, $uploads );
		$this->assertStringContainsString( "filename=\"{$filename}\"\r\nContent-Type: {$type}\r\n", $uploads[0]['raw'] );
	}

	/**
	 * 受け付ける形式。
	 *
	 * @return array
	 */
	public function accepted_formats() {
		return array(
			'jpeg' => array( self::JPEG . 'x', 'image.jpg', 'image/jpeg' ),
			'png'  => array( self::PNG . 'x', 'image.png', 'image/png' ),
			'webp' => array( self::WEBP . 'x', 'image.webp', 'image/webp' ),
		);
	}

	/**
	 * 応答の ID を PUT に入れ、結び付きの記録を立てる。
	 */
	public function test_uploaded_id_goes_into_the_put_and_is_recorded_as_attached() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
		$record = get_post_meta( $post, Sync_Image::RECORD, true );
		$this->assertSame( self::ID_A, $record['id'] );
		$this->assertTrue( $record['attached'] );
		$this->assertSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 保存の時点では上げず、印を残して cron に任せる。本文も画像と一緒に 1 回で送る。
	 */
	public function test_new_image_waits_for_cron_and_goes_with_the_body_in_one_put() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
		$this->assertNotFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );

		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertCount( 1, $this->puts() );
	}

	/**
	 * 送り直しの操作でも、保存の時点と同じく上げない。
	 */
	public function test_resend_defers_the_upload_too() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		Sync_Queue::resend( $post );

		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 画像が変わっていない更新では上げず、同じ ID を送る。保存の時点で送れる。
	 */
	public function test_update_without_image_change_does_not_upload_and_keeps_the_id() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => '別の題',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
		$this->assertCount( 1, $this->puts() );
		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
	}

	/**
	 * 同じ内容なら、画像があっても HTTP を出さない。
	 */
	public function test_same_content_and_image_send_nothing() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();

		Sync_Queue::mark( $post );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 差し替えると 1 回上げ、新しい ID を送る。
	 */
	public function test_replacing_the_image_uploads_once_and_sends_the_new_id() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests           = array();
		$this->upload_responses[] = $this->response( 201, array( 'id' => self::ID_B ) );

		set_post_thumbnail( $post, $this->attachment( self::JPEG . 'b' ) );
		Sync_Queue::mark( $post );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( self::ID_B, $this->last_put()['imageUploadId'] );
		$this->assertSame( self::ID_B, get_post_meta( $post, Sync_Image::RECORD, true )['id'] );
	}

	/**
	 * 別の添付でも、ファイルが同じなら上げない。同じ添付でも、ファイルが変われば上げる。
	 */
	public function test_identity_is_the_file_not_the_attachment() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();

		$same_bytes = $this->attachment( self::JPEG . 'a' );
		set_post_thumbnail( $post, $same_bytes );
		Sync_Queue::mark( $post );
		$this->run_queue();
		$this->assertSame( array(), $this->uploads() );

		file_put_contents( get_attached_file( $same_bytes ), self::JPEG . 'edited' );
		Sync_Queue::mark( $post );
		$this->run_queue();
		$this->assertCount( 1, $this->uploads() );
	}

	/**
	 * アイキャッチ画像を外すと、ID を送らず記録も消す。
	 */
	public function test_removing_the_image_sends_no_id_and_forgets_the_record() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		delete_post_thumbnail( $post );
		Sync_Queue::mark( $post );
		Sync_Queue::send_marked();

		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
		$this->assertSame( '', get_post_meta( $post, Sync_Image::RECORD, true ) );
	}

	/**
	 * 画像が無いイベントは今までと同じ本文を送る。
	 */
	public function test_event_without_image_sends_no_upload_and_no_id() {
		$this->event();
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
	}

	/**
	 * 本文の中の画像は送らない。
	 */
	public function test_images_in_the_content_are_not_uploaded_or_sent() {
		$post = $this->event();
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<p>本文</p><img src="https://example.com/a.jpg" alt="x">',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
		$this->assertStringNotContainsString( 'example.com/a.jpg', $this->last_put()['description'] );
	}

	/**
	 * 送れない投稿のために上げない。
	 */
	public function test_blocked_event_does_not_upload() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		delete_post_meta( $post, Event_Meta::CATEGORY );
		Sync_Queue::mark( $post );

		$this->run_queue();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 大きすぎる元のファイルは、縮小版を決めた順に試す。
	 */
	public function test_oversized_original_falls_back_to_the_largest_fitting_size() {
		$too_big = self::JPEG . str_repeat( 'x', Sync_Image::MAX_BYTES );
		$this->event(
			$this->attachment(
				$too_big,
				array(
					'thumbnail' => self::JPEG . 'thumb',
					'large'     => self::JPEG . 'large',
					'1536x1536' => self::JPEG . 'mid',
					'2048x2048' => $too_big,
				)
			)
		);

		$this->run_queue();

		$uploads = $this->uploads();
		$this->assertCount( 1, $uploads );
		$this->assertStringContainsString( self::JPEG . "mid\r\n", $uploads[0]['raw'] );
	}

	/**
	 * 上限ちょうどは送り、1 バイト超えると理由が出る。
	 */
	public function test_size_limit_is_inclusive() {
		$fits = str_repeat( 'x', Sync_Image::MAX_BYTES - strlen( self::JPEG ) );

		$this->event( $this->attachment( self::JPEG . $fits ) );
		$this->run_queue();
		$this->assertCount( 1, $this->uploads() );

		$over = $this->event( $this->attachment( self::JPEG . $fits . 'x' ) );
		$this->run_queue();
		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( Sync_Image::ISSUE_TOO_LARGE, get_post_meta( $over, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 切り抜かれる大きさは縮小版の候補にしない。
	 */
	public function test_cropped_sizes_are_not_candidates() {
		$post = $this->event( $this->attachment( self::GIF, array( 'thumbnail' => self::JPEG . 'thumb' ) ) );

		$this->run_queue();

		$this->assertSame( array(), $this->uploads() );
		$this->assertSame( Sync_Image::ISSUE_FORMAT, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 形式の違うファイルは、拡張子が .jpg でも上げず、理由が出て本文は送られる。
	 */
	public function test_unsupported_format_shows_a_reason_and_the_body_is_sent() {
		$post = $this->event( $this->attachment( self::GIF . 'x' ) );

		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
		$this->assertCount( 1, $this->puts() );
		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
		$this->assertSame( Sync_Image::ISSUE_FORMAT, get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * RIFF だけでは WebP としない。
	 */
	public function test_riff_without_webp_is_not_accepted() {
		$post = $this->event( $this->attachment( "RIFF\x00\x00\x00\x00WAVE" ) );

		Sync_Queue::send_marked();

		$this->assertSame( Sync_Image::ISSUE_FORMAT, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 差し替えた画像が合わないときは、前の ID のまま送る。
	 */
	public function test_unfit_replacement_keeps_the_previous_id() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		set_post_thumbnail( $post, $this->attachment( self::GIF ) );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => '別の題',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
		$this->assertSame( '別の題', $this->last_put()['title'] );
		$this->assertSame( Sync_Image::ISSUE_FORMAT, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * ファイルが読めないときは、理由が出て本文は送られる。
	 */
	public function test_missing_file_shows_a_reason_and_the_body_is_sent() {
		$attachment = $this->attachment( self::JPEG . 'a' );
		unlink( get_attached_file( $attachment ) );
		$post = $this->event( $attachment );

		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->puts() );
		$this->assertSame( Sync_Image::ISSUE_UNREADABLE, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 投入の失敗の種類の表。
	 *
	 * @dataProvider upload_failures
	 *
	 * @param int|null $status 応答のステータス。null は接続の失敗。
	 * @param string   $issue  残る理由。
	 * @param bool     $hold   投入を止めるか。
	 */
	public function test_upload_failures_keep_the_body_going( $status, $issue, $hold ) {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->upload_responses[] = null === $status ? new WP_Error( 'http_request_failed', 'x' ) : $this->response( $status );
		$this->run_queue();

		$this->assertCount( 1, $this->puts(), '本文は送られる' );
		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
		$this->assertSame( $issue, get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Image::RECORD, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
		$this->assertSame( $hold, (int) get_option( Sync_Image::HOLD_OPTION, 0 ) > time() );
	}

	/**
	 * 本文を止めない失敗。
	 *
	 * @return array
	 */
	public function upload_failures() {
		return array(
			'400'   => array( 400, Sync_Image::ISSUE_REJECTED, false ),
			'404'   => array( 404, Sync_Image::ISSUE_REJECTED, false ),
			'413'   => array( 413, Sync_Image::ISSUE_TOO_LARGE, false ),
			'424'   => array( 424, Sync_Image::ISSUE_TEMPORARY, false ),
			'500'   => array( 500, Sync_Image::ISSUE_TEMPORARY, false ),
			'接続の失敗' => array( null, Sync_Image::ISSUE_TEMPORARY, false ),
			'409'   => array( 409, Sync_Image::ISSUE_QUOTA, true ),
		);
	}

	/**
	 * 201 でも ID が読めなければ、一時的な失敗として本文を送る。
	 */
	public function test_unreadable_upload_response_is_temporary() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->upload_responses[] = $this->response( 201, array( 'id' => '../../evil' ) );
		$this->run_queue();

		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
		$this->assertSame( Sync_Image::ISSUE_TEMPORARY, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 429 は本文も待つ。ほかの投稿も止める。
	 */
	public function test_rate_limited_upload_holds_the_body_too() {
		$first  = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$second = $this->event( $this->attachment( self::JPEG . 'b' ) );

		$this->upload_responses[] = $this->response( 429, null, array( 'retry-after' => '120' ) );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( array(), $this->puts() );
		$this->assertNotSame( '', get_post_meta( $first, Sync_Queue::PENDING, true ) );
		$this->assertNotSame( '', get_post_meta( $second, Sync_Queue::PENDING, true ) );
		$this->assertGreaterThan( time(), (int) get_option( Sync_Queue::HOLD_OPTION, 0 ) );
	}

	/**
	 * 401 と 403 は本文の送信と同じくその回を止める。
	 *
	 * @dataProvider halting_statuses
	 *
	 * @param int $status ステータス。
	 */
	public function test_halting_upload_stops_the_run( $status ) {
		$first = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->event( $this->attachment( self::JPEG . 'b' ) );

		$this->upload_responses[] = $this->response( $status );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( array(), $this->puts() );
		$this->assertNotSame( '', get_post_meta( $first, Sync_Queue::PENDING, true ) );
		$this->assertFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * その回を止めるステータス。
	 *
	 * @return array
	 */
	public function halting_statuses() {
		return array(
			'401' => array( 401 ),
			'403' => array( 403 ),
		);
	}

	/**
	 * 投入の失敗で、前の ID のまま送る。
	 */
	public function test_failed_replacement_keeps_the_previous_id() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		set_post_thumbnail( $post, $this->attachment( self::JPEG . 'b' ) );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => '別の題',
			)
		);
		$this->upload_responses[] = $this->response( 500 );
		$this->run_queue();

		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
		$this->assertSame( self::ID_A, get_post_meta( $post, Sync_Image::RECORD, true )['id'] );
	}

	/**
	 * 止まっている間は投入の HTTP を出さず、保存の時点でも本文を送る。
	 */
	public function test_quota_hold_skips_the_upload_and_sends_the_body() {
		update_option( Sync_Image::HOLD_OPTION, time() + 100, false );
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
		$this->assertCount( 1, $this->puts() );
		$this->assertSame( Sync_Image::ISSUE_QUOTA, get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 止める時間が過ぎれば上げる。
	 */
	public function test_expired_hold_uploads_again() {
		update_option( Sync_Image::HOLD_OPTION, time() - 1, false );
		$this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
	}

	/**
	 * 持っていた ID の、欄の名前の無い 400 では、記録を消して印を残し、次で上げ直す。
	 */
	public function test_stale_id_rejection_uploads_again_on_the_next_run() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => '別の題',
			)
		);
		$this->put_responses[] = $this->response( 400, array( 'message' => 'The specified image is not available.' ) );
		Sync_Queue::send_marked();

		$this->assertSame( '', get_post_meta( $post, Sync_Image::RECORD, true ) );
		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::ERROR, true ) );

		$this->upload_responses[] = $this->response( 201, array( 'id' => self::ID_B ) );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( self::ID_B, $this->last_put()['imageUploadId'] );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 上げた直後の ID で 400 なら画像のせいではない。失敗として出し、上げ直しを繰り返さない。
	 */
	public function test_rejection_of_a_fresh_id_is_a_failure() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->put_responses[] = $this->response( 400 );
		$this->run_queue();

		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::ERROR, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
		$this->assertSame( self::ID_A, get_post_meta( $post, Sync_Image::RECORD, true )['id'] );
	}

	/**
	 * 欄の名前つきの 400 と 400 以外では記録を消さない。
	 *
	 * @dataProvider other_rejections
	 *
	 * @param int        $status ステータス。
	 * @param array|null $body   本文。
	 */
	public function test_other_rejections_keep_the_record( $status, $body ) {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => '別の題',
			)
		);
		$this->put_responses[] = $this->response( $status, $body );
		Sync_Queue::send_marked();

		$this->assertSame( self::ID_A, get_post_meta( $post, Sync_Image::RECORD, true )['id'] );
		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::ERROR, true ) );
	}

	/**
	 * 記録を消さない断り。
	 *
	 * @return array
	 */
	public function other_rejections() {
		return array(
			'欄の名前つき' => array( 400, array( 'message' => 'Field[title]:Error[NotBlank:x]' ) ),
			'409'    => array( 409, null ),
		);
	}

	/**
	 * 結び付いていない古い ID は使わない。12 時間以内なら使う。
	 */
	public function test_unattached_id_expires() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		$record             = get_post_meta( $post, Sync_Image::RECORD, true );
		$record['attached'] = false;
		$record['at']       = time() - Sync_Image::UNATTACHED_TTL + 60;
		update_post_meta( $post, Sync_Image::RECORD, $record );
		$this->requests = array();

		Sync_Queue::mark( $post );
		$this->run_queue();
		$this->assertSame( array(), $this->uploads(), '12 時間以内は上げ直さない' );

		$record['at'] = time() - Sync_Image::UNATTACHED_TTL;
		update_post_meta( $post, Sync_Image::RECORD, $record );

		$this->upload_responses[] = $this->response( 201, array( 'id' => self::ID_B ) );
		Sync_Queue::mark( $post );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertSame( self::ID_B, $this->last_put()['imageUploadId'] );
	}

	/**
	 * 本文の送信が失敗しても、送り直しでは上げた ID を使い、上げ直さない。
	 */
	public function test_retry_of_the_put_reuses_the_uploaded_id() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->put_responses[] = $this->response( 500 );
		$this->run_queue();
		$this->assertFalse( get_post_meta( $post, Sync_Image::RECORD, true )['attached'] );

		delete_post_meta( $post, Sync_Queue::NEXT_AT );
		$this->run_queue();

		$this->assertCount( 1, $this->uploads() );
		$this->assertCount( 2, $this->puts() );
		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
		$this->assertTrue( get_post_meta( $post, Sync_Image::RECORD, true )['attached'] );
	}

	/**
	 * 印を積み直さない送り直しでも、理由は決まり直す。
	 */
	public function test_retry_of_the_put_clears_a_resolved_issue() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->upload_responses[] = $this->response( 500 );
		$this->put_responses[]    = $this->response( 500 );
		$this->run_queue();
		$this->assertSame( Sync_Image::ISSUE_TEMPORARY, get_post_meta( $post, Sync_Image::ISSUE, true ) );

		delete_post_meta( $post, Sync_Queue::NEXT_AT );
		$this->run_queue();

		$this->assertSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
	}

	/**
	 * 応答に別の ID が載っていれば結び付きを立てない。
	 */
	public function test_response_with_another_id_does_not_mark_attached() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->put_responses[] = $this->response(
			200,
			array(
				'applied' => false,
				'event'   => array(
					'status'        => 'pendingReview',
					'imageUploadId' => self::ID_B,
				),
			)
		);
		$this->run_queue();

		$this->assertFalse( get_post_meta( $post, Sync_Image::RECORD, true )['attached'] );
	}

	/**
	 * 失敗した投入も流量の上限に数える。
	 */
	public function test_failed_uploads_count_against_the_run_limit() {
		for ( $i = 0; $i < Sync_Queue::RUN_MAX; $i++ ) {
			$this->event( $this->attachment( self::JPEG . $i ) );
			$this->upload_responses[] = $this->response( 500 );
		}

		$this->run_queue();

		$this->assertCount( Sync_Queue::RUN_MAX, $this->requests );
	}

	/**
	 * 結び付いた ID は古くても使う。
	 */
	public function test_attached_id_does_not_expire() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		$record       = get_post_meta( $post, Sync_Image::RECORD, true );
		$record['at'] = time() - YEAR_IN_SECONDS;
		update_post_meta( $post, Sync_Image::RECORD, $record );
		$this->requests = array();

		Sync_Queue::mark( $post );
		$this->run_queue();

		$this->assertSame( array(), $this->uploads() );
	}

	/**
	 * 期限の切れた未紐付けの ID は、合わない差し替えのときも前の ID として送らない。
	 */
	public function test_expired_id_is_not_kept_as_the_previous_image() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		$record             = get_post_meta( $post, Sync_Image::RECORD, true );
		$record['attached'] = false;
		$record['at']       = time() - Sync_Image::UNATTACHED_TTL;
		update_post_meta( $post, Sync_Image::RECORD, $record );

		set_post_thumbnail( $post, $this->attachment( self::GIF ) );
		Sync_Queue::mark( $post );
		Sync_Queue::send_marked();

		$this->assertArrayNotHasKey( 'imageUploadId', $this->last_put() );
	}

	/**
	 * 反映されなかった応答では結び付きを立てない。
	 */
	public function test_not_applied_response_does_not_mark_attached() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->put_responses[] = $this->response(
			200,
			array(
				'applied' => false,
				'event'   => array(
					'status'        => 'pendingReview',
					'imageUploadId' => null,
				),
			)
		);
		$this->run_queue();

		$this->assertFalse( get_post_meta( $post, Sync_Image::RECORD, true )['attached'] );
	}

	/**
	 * 一時的な失敗と上限の投稿だけを積み直す。
	 */
	public function test_requeue_marks_only_retriable_issues() {
		$temporary = $this->event();
		$quota     = $this->event();
		$format    = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $temporary, Sync_Image::ISSUE, Sync_Image::ISSUE_TEMPORARY );
		update_post_meta( $quota, Sync_Image::ISSUE, Sync_Image::ISSUE_QUOTA );
		update_post_meta( $format, Sync_Image::ISSUE, Sync_Image::ISSUE_FORMAT );

		Sync_Image::requeue();

		$this->assertNotSame( '', get_post_meta( $temporary, Sync_Queue::PENDING, true ) );
		$this->assertNotSame( '', get_post_meta( $quota, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', get_post_meta( $format, Sync_Queue::PENDING, true ) );
		$this->assertNotFalse( wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 止まっている間は積み直さない。
	 */
	public function test_requeue_waits_for_the_hold() {
		$post = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::ISSUE, Sync_Image::ISSUE_QUOTA );
		update_option( Sync_Image::HOLD_OPTION, time() + 100, false );

		Sync_Image::requeue();

		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 1 回に積み直す数を絞り、古い順に回す。
	 */
	public function test_requeue_is_limited_and_rotates() {
		$first_ids = array();
		for ( $i = 0; $i < Sync_Image::REQUEUE_MAX + 1; $i++ ) {
			$post = $this->event();
			Sync_Queue::send_marked();
			update_post_meta( $post, Sync_Image::ISSUE, Sync_Image::ISSUE_TEMPORARY );
			$first_ids[] = $post;
		}

		Sync_Image::requeue();

		$pending = array_filter(
			$first_ids,
			static function ( $id ) {
				return '' !== get_post_meta( $id, Sync_Queue::PENDING, true );
			}
		);
		$this->assertCount( Sync_Image::REQUEUE_MAX, $pending );
		$this->assertSame( '', get_post_meta( end( $first_ids ), Sync_Queue::PENDING, true ) );
	}

	/**
	 * 定期の取り込みの cron が積み直す。URL が変わった複製では積まない。
	 */
	public function test_pull_run_requeues_unless_the_site_changed() {
		$post = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::ISSUE, Sync_Image::ISSUE_TEMPORARY );

		update_option( Site_Identity::URL_OPTION, 'https://elsewhere.example' );
		Sync_Pull::run();
		$this->assertSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );

		Site_Identity::use_as_moved();
		Sync_Pull::run();
		$this->assertNotSame( '', get_post_meta( $post, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 積み直して通れば、理由が消えて ID が送られる。
	 */
	public function test_retry_after_a_temporary_failure_attaches_the_image() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );

		$this->upload_responses[] = $this->response( 500 );
		$this->run_queue();

		Sync_Image::requeue();
		$this->run_queue();

		$this->assertSame( self::ID_A, $this->last_put()['imageUploadId'] );
		$this->assertSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertCount( 2, $this->puts() );
	}

	/**
	 * 投入も流量の上限に数える。
	 */
	public function test_uploads_count_against_the_run_limit() {
		for ( $i = 0; $i < Sync_Queue::RUN_MAX; $i++ ) {
			$this->event( $this->attachment( self::JPEG . $i ) );
		}

		$this->run_queue();

		$this->assertCount( Sync_Queue::RUN_MAX, $this->requests );
	}

	/**
	 * 複製のサイトでは上げない。
	 */
	public function test_copied_site_does_not_upload() {
		$this->event( $this->attachment( self::JPEG . 'a' ) );
		update_option( Site_Identity::URL_OPTION, 'https://elsewhere.example' );

		$this->run_queue();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * キーが無ければ HTTP を出さない。
	 */
	public function test_no_key_does_not_upload() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();
		delete_option( Connection::KEY_OPTION );

		set_post_thumbnail( $post, $this->attachment( self::JPEG . 'b' ) );
		Sync_Queue::mark( $post );
		$this->run_queue();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 取り下げでは上げない。
	 */
	public function test_unpublish_does_not_upload() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();
		$this->requests = array();

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->uploads() );
	}

	/**
	 * EventWatch に無いと分かったら記録を消す。
	 */
	public function test_clear_remote_forgets_the_record() {
		$post = $this->event( $this->attachment( self::JPEG . 'a' ) );
		$this->run_queue();

		Sync_Queue::clear_remote( $post );

		$this->assertSame( '', get_post_meta( $post, Sync_Image::RECORD, true ) );
	}

	/**
	 * 複製の選択で記録と止める時刻を消す。
	 */
	public function test_forget_all_removes_image_data() {
		$post = $this->event( $this->attachment( self::GIF ) );
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::RECORD, array( 'id' => self::ID_A ) );
		update_option( Sync_Image::HOLD_OPTION, time() + 100, false );

		Sync_Queue::forget_all();

		$this->assertSame( '', get_post_meta( $post, Sync_Image::RECORD, true ) );
		$this->assertSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );
		$this->assertFalse( get_option( Sync_Image::HOLD_OPTION ) );
	}

	/**
	 * 保存で理由を消す。次の送信で決まり直す。
	 */
	public function test_mark_clears_the_issue() {
		$post = $this->event( $this->attachment( self::GIF ) );
		Sync_Queue::send_marked();
		$this->assertNotSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );

		Sync_Queue::mark( $post );

		$this->assertSame( '', get_post_meta( $post, Sync_Image::ISSUE, true ) );
	}

	/**
	 * 状態の口に理由が出て、状態の語は変わらない。
	 */
	public function test_status_lists_the_issue_and_keeps_the_state() {
		$post = $this->event( $this->attachment( self::GIF ) );
		Sync_Queue::send_marked();

		$status = Sync_Rest::status( $post );

		$this->assertSame( 'review', $status['state'] );
		$this->assertCount( 1, $status['imageIssue'] );
		$this->assertSame( 'image-format', $status['imageIssue'][0]['code'] );
		$this->assertStringContainsString( 'JPEG', $status['imageIssue'][0]['message'] );
		$this->assertSame( array(), $status['blocks'] );
	}

	/**
	 * 理由の種類ごとに文がある。
	 *
	 * @dataProvider issue_codes
	 *
	 * @param string $code 理由。
	 */
	public function test_every_issue_has_a_message( $code ) {
		$post = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::ISSUE, $code );

		$issue = Sync_Rest::status( $post )['imageIssue'];

		$this->assertCount( 1, $issue );
		$this->assertNotSame( '', $issue[0]['message'] );
	}

	/**
	 * 理由の種類。
	 *
	 * @return array
	 */
	public function issue_codes() {
		return array(
			'format'     => array( Sync_Image::ISSUE_FORMAT ),
			'tooLarge'   => array( Sync_Image::ISSUE_TOO_LARGE ),
			'unreadable' => array( Sync_Image::ISSUE_UNREADABLE ),
			'rejected'   => array( Sync_Image::ISSUE_REJECTED ),
			'temporary'  => array( Sync_Image::ISSUE_TEMPORARY ),
			'quota'      => array( Sync_Image::ISSUE_QUOTA ),
		);
	}

	/**
	 * 「送らない」の間は理由を出さない。
	 */
	public function test_status_hides_the_issue_when_sending_is_off() {
		$post = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::ISSUE, Sync_Image::ISSUE_FORMAT );
		update_post_meta( $post, Sync_Queue::NO_SEND, 1 );

		$this->assertSame( array(), Sync_Rest::status( $post )['imageIssue'] );
	}

	/**
	 * 未認証の REST に記録と理由が出ない。
	 */
	public function test_unauthenticated_rest_does_not_expose_image_data() {
		$post = $this->event( $this->attachment( self::GIF ) );
		Sync_Queue::send_marked();
		update_post_meta( $post, Sync_Image::RECORD, array( 'id' => self::ID_A ) );

		wp_set_current_user( 0 );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $post ) );

		$encoded = wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( self::ID_A, $encoded );
		$this->assertStringNotContainsString( '_eventwatch_sync_image', $encoded );

		$this->assertTrue( is_protected_meta( Sync_Image::RECORD, 'post' ) );
		$this->assertTrue( is_protected_meta( Sync_Image::ISSUE, 'post' ) );
	}
}
