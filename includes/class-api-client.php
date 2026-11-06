<?php
/**
 * EventWatch の API を呼ぶクライアント。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面の操作と WP-Cron からだけ呼ぶ。サイトの表示の流れでは呼ばない。
 *
 * 戻りは array( 'status' => int, 'body' => mixed ) か WP_Error。WP_Error のコードで失敗の種類が分かる。
 * メッセージと data に、キーと応答の本文を入れない。
 */
class Api_Client {

	const DEFAULT_BASE_URL = 'https://api.eventwatch.app';

	/**
	 * Web とアプリと 1 文字も違えない。LB のキャッシュのキーに生の値が入る。
	 */
	const BEHAVIOR = 'EventBehavior.description=omitted,EventBehavior.image=ogp,EventBehavior.format=md';

	const TIMEOUT = 30;

	/**
	 * 429 に Retry-After が無いときに待つ秒数。
	 */
	const DEFAULT_RATE_LIMIT_WAIT = 60;

	/**
	 * 応答の Retry-After をそのまま信じない上限の秒数。
	 */
	const MAX_RETRY_AFTER = DAY_IN_SECONDS;

	/**
	 * 503 を同じ要求の中で送り直す Retry-After の上限。これを超える値は「すぐ戻ってくるな」という指示。
	 */
	const MAX_INLINE_RETRY_AFTER = 2;

	const NO_KEY          = 'eventwatch_no_key';
	const SITE_CHANGED    = 'eventwatch_site_changed';
	const UNAUTHORIZED    = 'eventwatch_unauthorized';
	const SUSPENDED       = 'eventwatch_suspended';
	const FORBIDDEN       = 'eventwatch_forbidden';
	const NOT_FOUND       = 'eventwatch_not_found';
	const INVALID_REQUEST = 'eventwatch_invalid_request';
	const RATE_LIMITED    = 'eventwatch_rate_limited';
	const TEMPORARY       = 'eventwatch_temporary';

	/**
	 * 団体の停止を表す応答の code。キーの問題ではないので、案内を分ける。
	 */
	const CODE_SUSPENDED = 'organizationSuspended';

	/**
	 * このサイトを送った別のキーが有効なまま残っていることを表す 403 の code。古いキーを失効すれば直るので、案内を分ける。
	 * 止まり方はほかの 403 と同じ（FORBIDDEN）。
	 */
	const CODE_SITE_BOUND = 'siteBoundToAnotherKey';

	/**
	 * 送り直しの対象の失敗か。data の retry_after は待つ秒数で、指定が無ければ 0。429 はその間送らない。
	 *
	 * @param WP_Error $error 失敗。
	 * @return bool
	 */
	public static function is_temporary( WP_Error $error ) {
		return in_array( $error->get_error_code(), array( self::RATE_LIMITED, self::TEMPORARY ), true );
	}

	/**
	 * API を呼ぶ。
	 *
	 * @param string $method メソッド。
	 * @param string $path   `/api/` で始まるパス。
	 * @param array  $args   body（JSON にする配列）か multipart（fields と file）と、timeout（秒）。
	 * @return array|WP_Error
	 */
	public function request( $method, $path, $args = array() ) {
		$method = strtoupper( $method );
		$key    = Connection::api_key();

		if ( '' === $key ) {
			return new WP_Error( self::NO_KEY, 'No EventWatch API key is set.' );
		}

		// 複製のサイトから本番の投稿を書き換えない。読むだけなら本番は変わらない。
		if ( 'GET' !== $method && Site_Identity::has_changed() ) {
			return new WP_Error( self::SITE_CHANGED, 'The site URL has changed. Sync is stopped until an administrator confirms.' );
		}

		$response = $this->send( $method, $path, $key, $args );

		$retry_after = $this->inline_retry_after( $method, $response );
		if ( $retry_after > 0 ) {
			$this->wait( $retry_after * ( 1 + wp_rand( 0, 20 ) / 100 ) );
			$response = $this->send( $method, $path, $key, $args );
		}

		$result = $this->interpret( $response );
		Connection::observe( $result );

		return $result;
	}

	/**
	 * API の URL の前半。
	 *
	 * @return string
	 */
	public static function base_url() {
		return untrailingslashit( defined( 'EVENTWATCH_API_URL' ) ? (string) EVENTWATCH_API_URL : self::DEFAULT_BASE_URL );
	}

	/**
	 * 待つ。テストで差し替える。
	 *
	 * @param float $seconds 秒。
	 * @return void
	 */
	protected function wait( $seconds ) {
		usleep( (int) ( $seconds * 1000000 ) );
	}

	/**
	 * 1 度送る。
	 *
	 * @param string $method メソッド。
	 * @param string $path   パス。
	 * @param string $key    API キー。
	 * @param array  $args   body か multipart と、timeout。
	 * @return array|WP_Error
	 */
	private function send( $method, $path, $key, $args ) {
		$headers = array(
			'X-EW-Api-Key'  => $key,
			'X-EW-Behavior' => self::BEHAVIOR,
			'Accept'        => 'application/json',
		);
		$request = array(
			'method'      => $method,
			'timeout'     => isset( $args['timeout'] ) ? (float) $args['timeout'] : self::TIMEOUT,
			// キーのヘッダーを別のホストへ運ばない。
			'redirection' => 0,
			// 既定の User-Agent はサイトの URL を含む。この要求で送るものを増やさない。
			'user-agent'  => 'EventWatch-WordPress',
			'headers'     => $headers,
		);

		if ( isset( $args['body'] ) ) {
			$request['headers']['Content-Type'] = 'application/json';
			$request['body']                    = wp_json_encode( $args['body'] );
		} elseif ( isset( $args['multipart'] ) ) {
			$boundary                           = 'eventwatch' . wp_generate_password( 24, false );
			$request['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$request['body']                    = $this->multipart_body( $args['multipart'], $boundary );
		}

		return wp_remote_request( self::base_url() . $path, $request );
	}

	/**
	 * Multipart の本文を組み立てる。
	 *
	 * @param array{fields: array<string, string>, file: array{name: string, filename: string, type: string, content: string}} $multipart パート。
	 * @param string                                                                                                           $boundary  区切り。
	 * @return string
	 */
	private function multipart_body( array $multipart, $boundary ) {
		$body = '';
		foreach ( $multipart['fields'] as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		$file = $multipart['file'];

		return $body . "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$file['name']}\"; filename=\"{$file['filename']}\"\r\nContent-Type: {$file['type']}\r\n\r\n{$file['content']}\r\n--{$boundary}--\r\n";
	}

	/**
	 * 同じ要求の中で送り直すなら、Retry-After の秒数を返す。送り直さないなら 0。
	 *
	 * GET だけ、503 で Retry-After が 1〜2 秒のときだけ。回数を増やさない。文の打ち切りは逼迫と恒常的な遅さを
	 * 区別できず、送り直し続けると直らない不具合が混雑に見える。
	 *
	 * @param string         $method   メソッド。
	 * @param array|WP_Error $response 応答。
	 * @return int
	 */
	private function inline_retry_after( $method, $response ) {
		if ( 'GET' !== $method || is_wp_error( $response ) || 503 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$seconds = $this->retry_after( $response );

		return $seconds <= self::MAX_INLINE_RETRY_AFTER ? $seconds : 0;
	}

	/**
	 * Retry-After の秒数。正の整数として読めなければ 0。日時の形は読まない。
	 *
	 * @param array $response 応答。
	 * @return int
	 */
	private function retry_after( $response ) {
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
			return 0;
		}

		return min( (int) $value, self::MAX_RETRY_AFTER );
	}

	/**
	 * 応答を、成功の配列か種類の分かる WP_Error にする。
	 *
	 * @param array|WP_Error $response 応答。
	 * @return array|WP_Error
	 */
	private function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::TEMPORARY, 'Could not connect to EventWatch.', array( 'retry_after' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			return $this->interpret_success( $status, $body );
		}

		$data = array( 'status' => $status );
		$code = $this->error_code( $body );

		// 呼び出し側が理由を見分けるため。語だけを渡し、応答の本文は渡さない。
		if ( preg_match( '/^[A-Za-z]{1,64}$/', $code ) ) {
			$data['code'] = $code;
		}

		if ( 401 === $status ) {
			return new WP_Error( self::UNAUTHORIZED, 'The EventWatch API key was rejected.', $data );
		}

		if ( 403 === $status ) {
			return self::CODE_SUSPENDED === $code
				? new WP_Error( self::SUSPENDED, 'The EventWatch organization is suspended.', $data )
				: new WP_Error( self::FORBIDDEN, 'The EventWatch API key cannot do this.', $data );
		}

		if ( 404 === $status ) {
			return new WP_Error( self::NOT_FOUND, 'EventWatch could not find this.', $data );
		}

		if ( 429 === $status ) {
			$seconds             = $this->retry_after( $response );
			$data['retry_after'] = $seconds > 0 ? $seconds : self::DEFAULT_RATE_LIMIT_WAIT;
			return new WP_Error( self::RATE_LIMITED, 'EventWatch asked to slow down.', $data );
		}

		if ( $status >= 400 && $status < 500 ) {
			$fields = 400 === $status ? $this->error_fields( $body ) : array();
			if ( $fields ) {
				$data['fields'] = $fields;
			}
			return new WP_Error( self::INVALID_REQUEST, 'EventWatch rejected the request.', $data );
		}

		// 5xx と、追わないことにした 3xx。
		$data['retry_after'] = $this->retry_after( $response );

		return new WP_Error( self::TEMPORARY, 'EventWatch is temporarily unavailable.', $data );
	}

	/**
	 * 2xx の本文を読む。読めない本文は、途中の機器の応答かもしれないので一時的な失敗にする。
	 *
	 * @param int    $status ステータス。
	 * @param string $body   本文。
	 * @return array|WP_Error
	 */
	private function interpret_success( $status, $body ) {
		if ( '' === $body ) {
			return array(
				'status' => $status,
				'body'   => null,
			);
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				self::TEMPORARY,
				'EventWatch returned an unreadable response.',
				array(
					'status'      => $status,
					'retry_after' => 0,
				)
			);
		}

		return array(
			'status' => $status,
			'body'   => $decoded,
		);
	}

	/**
	 * 400 の本文で断られた欄の名前。文は英語で利用者向けでないので、名前だけを読む。
	 *
	 * 欄の配列は無く、message に `Field[title]:Error[…]` の形でつないである。添字つきの欄は先頭の名前にする。
	 *
	 * @param string $body 本文。
	 * @return string[]
	 */
	private function error_fields( $body ) {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['message'] ) || ! is_string( $decoded['message'] ) ) {
			return array();
		}

		preg_match_all( '/Field\[([A-Za-z]{1,64})/', $decoded['message'], $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * 誤りの本文の code。
	 *
	 * @param string $body 本文。
	 * @return string
	 */
	private function error_code( $body ) {
		$decoded = json_decode( $body, true );

		return is_array( $decoded ) && isset( $decoded['code'] ) && is_string( $decoded['code'] ) ? $decoded['code'] : '';
	}
}
