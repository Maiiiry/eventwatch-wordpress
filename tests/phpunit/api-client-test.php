<?php
/**
 * API クライアントと接続の確認のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Api_Client;
use EventWatch\Connection;
use EventWatch\Site_Identity;
use WP_Error;
use WP_UnitTestCase;

/**
 * 待ちを記録するだけのクライアント。
 */
class Waitless_Api_Client extends Api_Client {

	/**
	 * 待った秒数。
	 *
	 * @var float[]
	 */
	public $waits = array();

	/**
	 * 待たずに記録する。
	 *
	 * @param float $seconds 秒。
	 * @return void
	 */
	protected function wait( $seconds ) {
		$this->waits[] = $seconds;
	}
}

/**
 * Api_Client と Connection のテスト。HTTP は pre_http_request で返す。
 */
class Api_Client_Test extends WP_UnitTestCase {

	const KEY = 'ewk_a1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Bxyz1';

	const PATH = '/api/v1.0/org-api/organization';

	/**
	 * 送った要求。
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * キーを入れる。
	 */
	public function set_up() {
		parent::set_up();
		$this->sent = array();
		update_option( Connection::KEY_OPTION, self::KEY );
		Connection::forget();
		Site_Identity::use_as_moved();
	}

	/**
	 * 応答を順に返す。尽きたら最後の応答を返し続ける。
	 *
	 * @param array|WP_Error ...$responses 応答。
	 * @return void
	 */
	private function respond_with( ...$responses ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$responses ) {
				$this->sent[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return count( $responses ) > 1 ? array_shift( $responses ) : $responses[0];
			},
			10,
			3
		);
	}

	/**
	 * HTTP の応答を作る。
	 *
	 * @param int    $code    ステータス。
	 * @param string $body    本文。
	 * @param array  $headers ヘッダー。
	 * @return array
	 */
	private function http( $code, $body = '{}', array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 成功の本文を読み、取り決めのヘッダーを送る。キーを Authorization に載せず、リダイレクトを追わない。
	 */
	public function test_success_and_request_shape() {
		$this->respond_with( $this->http( 200, '{"displayName":"Hall"}' ) );

		$result = ( new Api_Client() )->request( 'get', self::PATH );

		$this->assertSame(
			array(
				'status' => 200,
				'body'   => array( 'displayName' => 'Hall' ),
			),
			$result
		);
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'https://api.eventwatch.app' . self::PATH, $this->sent[0]['url'] );

		$args = $this->sent[0]['args'];
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( self::KEY, $args['headers']['X-EW-Api-Key'] );
		$this->assertSame( Api_Client::BEHAVIOR, $args['headers']['X-EW-Behavior'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $args['user-agent'] );
		$this->assertSame( 30.0, (float) $args['timeout'] );
	}

	/**
	 * 本文は JSON で送り、タイムアウトは呼び出し側が変えられる。
	 */
	public function test_body_and_timeout() {
		$this->respond_with( $this->http( 204, '' ) );

		$result = ( new Api_Client() )->request(
			'PUT',
			'/api/v1.0/org-api/x',
			array(
				'body'    => array( 'title' => 'A & B' ),
				'timeout' => 3,
			)
		);

		$this->assertSame(
			array(
				'status' => 204,
				'body'   => null,
			),
			$result
		);
		$this->assertSame( '{"title":"A & B"}', $this->sent[0]['args']['body'] );
		$this->assertSame( 'application/json', $this->sent[0]['args']['headers']['Content-Type'] );
		$this->assertSame( 3.0, (float) $this->sent[0]['args']['timeout'] );
	}

	/**
	 * 失敗の種類の表。
	 *
	 * @return array[]
	 */
	public function failures() {
		return array(
			'401'             => array( 401, '{}', array(), Api_Client::UNAUTHORIZED, false ),
			'403 suspended'   => array( 403, '{"code":"organizationSuspended"}', array(), Api_Client::SUSPENDED, false ),
			'403 other'       => array( 403, '{"code":"other"}', array(), Api_Client::FORBIDDEN, false ),
			'403 broken body' => array( 403, '<html>', array(), Api_Client::FORBIDDEN, false ),
			'403 list body'   => array( 403, '["organizationSuspended"]', array(), Api_Client::FORBIDDEN, false ),
			'404'             => array( 404, '{}', array(), Api_Client::NOT_FOUND, false ),
			'400'             => array( 400, '{}', array(), Api_Client::INVALID_REQUEST, false ),
			'422'             => array( 422, '{}', array(), Api_Client::INVALID_REQUEST, false ),
			'429'             => array( 429, '{}', array(), Api_Client::RATE_LIMITED, true ),
			'500'             => array( 500, '{}', array(), Api_Client::TEMPORARY, true ),
			'503'             => array( 503, '{}', array(), Api_Client::TEMPORARY, true ),
			'302'             => array( 302, '', array(), Api_Client::TEMPORARY, true ),
			'broken JSON'     => array( 200, '<html>proxy</html>', array(), Api_Client::TEMPORARY, true ),
		);
	}

	/**
	 * 応答から失敗の種類が分かり、一時的な失敗だけが送り直しの対象になる。
	 *
	 * @dataProvider failures
	 *
	 * @param int    $code      ステータス。
	 * @param string $body      本文。
	 * @param array  $headers   ヘッダー。
	 * @param string $expected  コード。
	 * @param bool   $temporary 一時的か。
	 */
	public function test_failure_kinds( $code, $body, $headers, $expected, $temporary ) {
		$this->respond_with( $this->http( $code, $body, $headers ) );

		$result = ( new Api_Client() )->request( 'POST', self::PATH );

		$this->assertWPError( $result );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertSame( $temporary, Api_Client::is_temporary( $result ) );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( array( $result->get_error_message(), $result->get_error_data() ) ) );
		$this->assertStringNotContainsString( 'proxy', (string) wp_json_encode( $result->get_error_data() ) );
	}

	/**
	 * タイムアウトは一時的な失敗で、本体のメッセージを持ち出さない。
	 */
	public function test_timeout() {
		$this->respond_with( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$result = ( new Waitless_Api_Client() )->request( 'GET', self::PATH );

		$this->assertSame( Api_Client::TEMPORARY, $result->get_error_code() );
		$this->assertTrue( Api_Client::is_temporary( $result ) );
		$this->assertStringNotContainsString( 'cURL', $result->get_error_message() );
		$this->assertSame( 0, $result->get_error_data()['retry_after'] );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * 429 は Retry-After を呼び出し側へ返す。code の無い手前の 429 も同じ。無ければ既定の秒数。
	 */
	public function test_rate_limit_returns_retry_after() {
		$this->respond_with(
			$this->http( 429, '{"code":"orgApiKeyRateLimitExceeded"}', array( 'retry-after' => '37' ) ),
			$this->http( 429, '', array( 'retry-after' => '5' ) ),
			$this->http( 429 ),
			$this->http( 429, '{}', array( 'retry-after' => 'soon' ) ),
			$this->http( 429, '{}', array( 'retry-after' => '99999999' ) )
		);
		$client = new Waitless_Api_Client();

		$this->assertSame( 37, $client->request( 'POST', self::PATH )->get_error_data()['retry_after'] );
		$this->assertSame( 5, $client->request( 'POST', self::PATH )->get_error_data()['retry_after'] );
		$this->assertSame( Api_Client::DEFAULT_RATE_LIMIT_WAIT, $client->request( 'GET', self::PATH )->get_error_data()['retry_after'] );
		$this->assertSame( Api_Client::DEFAULT_RATE_LIMIT_WAIT, $client->request( 'GET', self::PATH )->get_error_data()['retry_after'] );
		$this->assertSame( DAY_IN_SECONDS, $client->request( 'GET', self::PATH )->get_error_data()['retry_after'] );
		$this->assertSame( array(), $client->waits );
		$this->assertCount( 5, $this->sent );
	}

	/**
	 * 503 と Retry-After 1〜2 の GET は 1 度だけ送り直す。待ちは値以上で、上へ 20% まで。
	 *
	 * @testWith ["1"]
	 *           ["2"]
	 *
	 * @param string $retry_after Retry-After。
	 */
	public function test_retries_get_once_on_short_retry_after( $retry_after ) {
		$this->respond_with(
			$this->http( 503, '{}', array( 'retry-after' => $retry_after ) ),
			$this->http( 200, '{"ok":true}' )
		);
		$client = new Waitless_Api_Client();

		$result = $client->request( 'GET', self::PATH );

		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 2, $this->sent );
		$this->assertCount( 1, $client->waits );
		$this->assertGreaterThanOrEqual( (float) $retry_after, $client->waits[0] );
		$this->assertLessThanOrEqual( (float) $retry_after * 1.2, $client->waits[0] );
	}

	/**
	 * 2 回目も 503 なら、そこで止める。
	 */
	public function test_retries_only_once() {
		$this->respond_with( $this->http( 503, '{}', array( 'retry-after' => '1' ) ) );
		$client = new Waitless_Api_Client();

		$result = $client->request( 'GET', self::PATH );

		$this->assertSame( Api_Client::TEMPORARY, $result->get_error_code() );
		$this->assertSame( 1, $result->get_error_data()['retry_after'] );
		$this->assertCount( 2, $this->sent );
	}

	/**
	 * 10 秒・範囲の外・読めない値・無いときは送り直さない。
	 *
	 * @testWith ["10"]
	 *           ["3"]
	 *           ["0"]
	 *           [""]
	 *           ["abc"]
	 *           ["1.5"]
	 *           ["-1"]
	 *           ["Wed, 21 Oct 2026 07:28:00 GMT"]
	 *
	 * @param string $retry_after Retry-After。
	 */
	public function test_does_not_retry_other_retry_after( $retry_after ) {
		$this->respond_with( $this->http( 503, '{}', array( 'retry-after' => $retry_after ) ) );
		$client = new Waitless_Api_Client();

		$result = $client->request( 'GET', self::PATH );

		$this->assertSame( Api_Client::TEMPORARY, $result->get_error_code() );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( array(), $client->waits );
	}

	/**
	 * ヘッダーが無いときも送り直さない。Retry-After が重複して配列になった値も読まない。
	 */
	public function test_does_not_retry_without_a_single_retry_after() {
		$this->respond_with(
			$this->http( 503 ),
			$this->http( 503, '{}', array( 'retry-after' => array( '1', '1' ) ) )
		);
		$client = new Waitless_Api_Client();

		$client->request( 'GET', self::PATH );
		$client->request( 'GET', self::PATH );

		$this->assertCount( 2, $this->sent );
		$this->assertSame( array(), $client->waits );
	}

	/**
	 * GET 以外は Retry-After が 1 でも送り直さない。
	 *
	 * @testWith ["POST"]
	 *           ["PUT"]
	 *           ["DELETE"]
	 *
	 * @param string $method メソッド。
	 */
	public function test_does_not_retry_writes( $method ) {
		$this->respond_with( $this->http( 503, '{}', array( 'retry-after' => '1' ) ) );
		$client = new Waitless_Api_Client();

		$result = $client->request( $method, self::PATH );

		$this->assertSame( 1, $result->get_error_data()['retry_after'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( array(), $client->waits );
	}

	/**
	 * 503 以外の 5xx は Retry-After が 1 でも送り直さない。
	 */
	public function test_retries_only_on_503() {
		$this->respond_with( $this->http( 500, '{}', array( 'retry-after' => '1' ) ) );

		( new Waitless_Api_Client() )->request( 'GET', self::PATH );

		$this->assertCount( 1, $this->sent );
	}

	/**
	 * キーが無ければ HTTP を出さない。
	 */
	public function test_no_key_sends_nothing() {
		delete_option( Connection::KEY_OPTION );
		$this->respond_with( $this->http( 200 ) );

		$result = ( new Api_Client() )->request( 'GET', self::PATH );

		$this->assertSame( Api_Client::NO_KEY, $result->get_error_code() );
		$this->assertFalse( Api_Client::is_temporary( $result ) );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * 固定のキーは保存した値より優先する。
	 */
	public function test_fixed_key_wins() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return 'ewk_fixed';
			}
		);
		$this->respond_with( $this->http( 200 ) );

		( new Api_Client() )->request( 'GET', self::PATH );

		$this->assertSame( 'ewk_fixed', $this->sent[0]['args']['headers']['X-EW-Api-Key'] );
	}

	/**
	 * 接続の確認で団体名を残す。タグは落とす。短いタイムアウトで呼ぶ。
	 */
	public function test_check_saves_the_organization() {
		$this->respond_with( $this->http( 200, '{"id":"1","displayName":"<b>Tom\'s</b> Hall","scope":"sync"}' ) );

		$state = Connection::check();

		$this->assertSame( Connection::CONNECTED, $state['status'] );
		$this->assertSame( "Tom's Hall", $state['organization'] );
		$this->assertGreaterThan( 0, $state['checked_at'] );
		$this->assertSame( self::PATH, wp_parse_url( $this->sent[0]['url'], PHP_URL_PATH ) );
		$this->assertSame( (float) Connection::CHECK_TIMEOUT, (float) $this->sent[0]['args']['timeout'] );
	}

	/**
	 * 団体名の無い 2xx はつながったことにしない。
	 *
	 * @testWith ["{}"]
	 *           ["{\"displayName\":123}"]
	 *           ["{\"displayName\":\"<script></script>\"}"]
	 *           ["[]"]
	 *           ["\"x\""]
	 *           [""]
	 *           ["<html>"]
	 *
	 * @param string $body 本文。
	 */
	public function test_check_rejects_bad_bodies( $body ) {
		$this->respond_with( $this->http( 200, $body ) );

		$this->assertSame( Connection::UNREACHABLE, Connection::check()['status'] );
	}

	/**
	 * 確かめた結果の状態の表。
	 *
	 * @return array[]
	 */
	public function check_states() {
		return array(
			'401'       => array( 401, '{}', Connection::UNAUTHORIZED ),
			'suspended' => array( 403, '{"code":"organizationSuspended"}', Connection::SUSPENDED ),
			'403'       => array( 403, '{}', Connection::FORBIDDEN ),
			'404'       => array( 404, '{}', Connection::UNREACHABLE ),
			'400'       => array( 400, '{}', Connection::UNREACHABLE ),
			'429'       => array( 429, '{}', Connection::UNREACHABLE ),
			'503'       => array( 503, '{}', Connection::UNREACHABLE ),
		);
	}

	/**
	 * 応答ごとの状態。
	 *
	 * @dataProvider check_states
	 *
	 * @param int    $code     ステータス。
	 * @param string $body     本文。
	 * @param string $expected 状態。
	 */
	public function test_check_states( $code, $body, $expected ) {
		$this->respond_with( $this->http( $code, $body ) );

		$this->assertSame( $expected, Connection::check()['status'] );
	}

	/**
	 * キーが無ければ、確かめても未確認のまま。
	 */
	public function test_check_without_key() {
		delete_option( Connection::KEY_OPTION );

		$this->assertSame( Connection::UNCHECKED, Connection::check()['status'] );
	}

	/**
	 * どの要求でも失効は状態に残り、2xx で未確認へ戻る。一時的な失敗では変わらない。
	 */
	public function test_requests_update_the_state() {
		$this->respond_with(
			$this->http( 200, '{"displayName":"Hall"}' ),
			$this->http( 503 ),
			$this->http( 401 ),
			$this->http( 503 ),
			$this->http( 200 )
		);
		$client = new Waitless_Api_Client();

		Connection::check();
		$client->request( 'POST', '/api/v1.0/org-api/x' );
		$this->assertSame( Connection::CONNECTED, Connection::state()['status'] );

		$client->request( 'POST', '/api/v1.0/org-api/x' );
		$this->assertSame( Connection::UNAUTHORIZED, Connection::state()['status'] );

		$client->request( 'POST', '/api/v1.0/org-api/x' );
		$this->assertSame( Connection::UNAUTHORIZED, Connection::state()['status'] );

		$client->request( 'POST', '/api/v1.0/org-api/x' );
		$this->assertSame( Connection::UNCHECKED, Connection::state()['status'] );
	}

	/**
	 * 通ったら、確かめたときの失敗の案内を残さない。
	 *
	 * @testWith [404]
	 *           [403]
	 *
	 * @param int $code 確かめたときのステータス。
	 */
	public function test_success_clears_other_failures( $code ) {
		$this->respond_with( $this->http( $code ), $this->http( 200 ) );

		$this->assertNotSame( Connection::UNCHECKED, Connection::check()['status'] );

		( new Api_Client() )->request( 'POST', '/api/v1.0/org-api/x' );

		$this->assertSame( Connection::UNCHECKED, Connection::state()['status'] );
	}

	/**
	 * 成功は、つながっている状態を消さない。
	 */
	public function test_success_keeps_connected_state() {
		$this->respond_with( $this->http( 200, '{"displayName":"Hall"}' ) );

		Connection::check();
		( new Api_Client() )->request( 'GET', self::PATH );

		$this->assertSame( Connection::CONNECTED, Connection::state()['status'] );
		$this->assertSame( 'Hall', Connection::state()['organization'] );
	}

	/**
	 * キーが替わると、前のキーの結果を読まない。保存を通らない固定のキーの入れ替えでも同じ。
	 */
	public function test_state_belongs_to_the_key() {
		$this->respond_with( $this->http( 200, '{"displayName":"Hall"}' ) );
		Connection::check();

		update_option( Connection::KEY_OPTION, 'ewk_' . str_repeat( 'z', 43 ) );
		$this->assertSame( Connection::UNCHECKED, Connection::state()['status'] );

		update_option( Connection::KEY_OPTION, self::KEY );
		$this->assertSame( Connection::CONNECTED, Connection::state()['status'] );

		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return 'ewk_fixed';
			}
		);
		$this->assertSame( Connection::UNCHECKED, Connection::state()['status'] );
	}

	/**
	 * 状態の option にキーの平文を持たない。
	 */
	public function test_state_does_not_store_the_key() {
		$this->respond_with( $this->http( 200, '{"displayName":"Hall"}' ) );

		Connection::check();

		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( get_option( Connection::STATE_OPTION ) ) );
	}

	/**
	 * 伏せた形は接頭辞だけを残す。
	 */
	public function test_masked_key() {
		$masked = Connection::masked_key();

		$this->assertStringStartsWith( substr( self::KEY, 0, 12 ), $masked );
		$this->assertStringNotContainsString( substr( self::KEY, 12, 4 ), str_replace( substr( self::KEY, 0, 12 ), '', $masked ) );
		$this->assertStringEndsWith( '••••', $masked );

		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return 'short-secret';
			}
		);
		$this->assertStringNotContainsString( 'short', Connection::masked_key() );
		remove_all_filters( 'eventwatch_fixed_api_key' );

		delete_option( Connection::KEY_OPTION );
		$this->assertSame( '', Connection::masked_key() );
	}

	/**
	 * 誤りの本文の code を、語のときだけ data に渡す。ほかの本文は渡さない。
	 */
	public function test_error_data_carries_only_the_code_word() {
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Site_Identity::use_as_moved();
		$bodies = array();
		$answer = static function () use ( &$bodies ) {
			return array(
				'headers'  => array(),
				'body'     => array_shift( $bodies ),
				'response' => array( 'code' => 409 ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $answer, 1 );

		$bodies = array(
			'{"code":"eventDeleted","message":"secret text"}',
			'{"code":"not a word <b>"}',
			'{"message":"no code"}',
		);

		$deleted = ( new Api_Client() )->request( 'PUT', '/api/x', array( 'body' => array() ) );
		$odd     = ( new Api_Client() )->request( 'PUT', '/api/x', array( 'body' => array() ) );
		$none    = ( new Api_Client() )->request( 'PUT', '/api/x', array( 'body' => array() ) );

		remove_filter( 'pre_http_request', $answer, 1 );

		$this->assertSame(
			array(
				'status' => 409,
				'code'   => 'eventDeleted',
			),
			$deleted->get_error_data()
		);
		$this->assertSame( array( 'status' => 409 ), $odd->get_error_data() );
		$this->assertSame( array( 'status' => 409 ), $none->get_error_data() );
	}
}
