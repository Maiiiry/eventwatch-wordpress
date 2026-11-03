<?php
/**
 * 編集画面が読む送信の状態のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Capabilities;
use EventWatch\Connection;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Queue;
use EventWatch\Sync_Rest;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Sync_Rest のテスト。
 */
class Sync_Rest_Test extends WP_UnitTestCase {

	/**
	 * 次の応答。
	 *
	 * @var array|\WP_Error|null
	 */
	private $next = null;

	/**
	 * 受けた要求の数。
	 *
	 * @var int
	 */
	private $sent = 0;

	/**
	 * テストごとに登録し直し、HTTP を差し替える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		Capabilities::grant();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Site_Identity::use_as_moved();
		$this->sent = 0;
		$this->next = null;
		add_filter(
			'pre_http_request',
			function () {
				++$this->sent;
				return $this->next ?? $this->answer( 200, array( 'event' => array( 'status' => 'pendingReview' ) ) );
			}
		);
	}

	/**
	 * 状態を戻す。
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Sync_Queue::forget_all();
		parent::tear_down();
	}

	/**
	 * 応答を作る。
	 *
	 * @param int        $status ステータス。
	 * @param array|null $body   本文。
	 * @return array
	 */
	private function answer( $status, $body = null ) {
		return array(
			'headers'  => array(),
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 送れる内容で公開したイベント。印を積んだまま返す。
	 *
	 * @return int
	 */
	private function event() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => '題',
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
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		return $id;
	}

	/**
	 * 応答の投稿から状態を確かめる。
	 *
	 * @return array
	 */
	public function remote_states() {
		return array(
			'審査待ち'  => array( array( 'status' => 'pendingReview' ), 'review' ),
			'掲載中'   => array(
				array(
					'status'  => 'published',
					'visible' => true,
				),
				'live',
			),
			'差し戻し'  => array(
				array(
					'status'          => 'rejected',
					'rejectionReason' => '時刻を直してください',
				),
				'fix',
			),
			'運営の停止' => array(
				array(
					'status'              => 'published',
					'suspendedByOperator' => true,
				),
				'stopped',
			),
			'非公開'   => array( array( 'status' => 'private' ), 'withdrawn' ),
			'下書き'   => array( array( 'status' => 'draft' ), 'withdrawn' ),
		);
	}

	/**
	 * 送信の応答から、板の状態を出す。
	 *
	 * @dataProvider remote_states
	 *
	 * @param array  $event    応答の投稿。
	 * @param string $expected 状態。
	 */
	public function test_state_from_response( $event, $expected ) {
		$id         = $this->event();
		$this->next = $this->answer( 200, array( 'event' => $event ) );
		Sync_Queue::send_marked();

		$status = Sync_Rest::status( $id );

		$this->assertSame( $expected, $status['state'] );
		$this->assertNotSame( '', $status['label'] );
		$this->assertSame( 'fix' === $expected ? '時刻を直してください' : '', $status['reason']['text'] );
		$this->assertNotSame( '', $status['meta']['value'] );
		$this->assertFalse( $status['canResend'] );
	}

	/**
	 * 未送信・送信待ち・送らない。
	 */
	public function test_states_before_sending() {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'unsent', Sync_Rest::status( $draft )['state'] );

		$queued = $this->event();
		$this->assertSame( 'queued', Sync_Rest::status( $queued )['state'] );

		update_post_meta( $queued, Sync_Queue::NO_SEND, 1 );
		$this->assertSame( 'off', Sync_Rest::status( $queued )['state'] );
	}

	/**
	 * 送信の失敗は、理由と次に送り直す時刻と再送のボタンを出す。
	 */
	public function test_failed_state() {
		$id         = $this->event();
		$this->next = $this->answer( 503 );
		Sync_Queue::send_marked();

		$status = Sync_Rest::status( $id );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertTrue( $status['canResend'] );
		$this->assertNotSame( '', $status['reason']['text'] );
		$this->assertSame( 'Next retry', $status['meta']['label'] );
	}

	/**
	 * 失敗の種類ごとに理由を分ける。
	 *
	 * @return array
	 */
	public function failure_reasons() {
		return array(
			'削除済み'    => array( 409, array( 'code' => 'eventDeleted' ), 'deleted on EventWatch' ),
			'URL の衝突' => array( 409, array(), 'official page' ),
			'検証エラー'   => array( 400, array(), 'didn’t accept' ),
			'失効'      => array( 401, array(), 'rejected this API key' ),
			'停止'      => array( 403, array( 'code' => 'organizationSuspended' ), 'suspended' ),
		);
	}

	/**
	 * 理由の文。
	 *
	 * @dataProvider failure_reasons
	 *
	 * @param int    $code   ステータス。
	 * @param array  $body   本文。
	 * @param string $phrase 文に含まれる語。
	 */
	public function test_failure_reason_text( $code, $body, $phrase ) {
		$id         = $this->event();
		$this->next = $this->answer( $code, $body );
		Sync_Queue::send_marked();

		$this->assertStringContainsString( $phrase, Sync_Rest::status( $id )['reason']['text'] );
	}

	/**
	 * 送れない理由と、直す欄の ID を返す。
	 */
	public function test_blocks_with_field_links() {
		$id = $this->event();
		update_post_meta( $id, Event_Meta::CATEGORY, 0 );
		update_post_meta( $id, Event_Meta::PREFECTURE, 0 );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<p>' . str_repeat( 'あ', 10001 ) . '</p>',
			)
		);
		Sync_Queue::send_marked();

		$blocks = Sync_Rest::status( $id )['blocks'];

		$this->assertSame( array( 'category', 'prefecture', 'descriptionTooLong' ), array_column( $blocks, 'code' ) );
		$this->assertSame( array( 'eventwatch-field-category', 'eventwatch-field-prefecture', '' ), array_column( $blocks, 'field' ) );
		$this->assertStringContainsString( '10,001', $blocks[2]['message'] );
		$this->assertStringContainsString( '10,000', $blocks[2]['message'] );
	}

	/**
	 * 要求を流す。
	 *
	 * @param string $method メソッド。
	 * @param string $route  経路。
	 * @param array  $params 値。
	 * @return \WP_REST_Response
	 */
	private function dispatch( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * 状態の口と再送の口は、編集できる人だけ。
	 */
	public function test_routes_require_edit_permission() {
		$id = $this->event();
		Sync_Queue::send_marked();
		$before = $this->sent;

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dispatch( 'GET', "/eventwatch/v1/events/$id/sync" )->get_status() );
		$this->assertSame( 401, $this->dispatch( 'POST', "/eventwatch/v1/events/$id/resend" )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->dispatch( 'GET', "/eventwatch/v1/events/$id/sync" )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', "/eventwatch/v1/events/$id/resend" )->get_status() );
		$this->assertSame( $before, $this->sent );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 200, $this->dispatch( 'GET', "/eventwatch/v1/events/$id/sync" )->get_status() );

		$other = self::factory()->post->create();
		$this->assertSame( 403, $this->dispatch( 'GET', "/eventwatch/v1/events/$other/sync" )->get_status() );
	}

	/**
	 * 今すぐ送り直すと、時刻を待たずに送り、結果を返す。
	 */
	public function test_resend_sends_now() {
		$id         = $this->event();
		$this->next = $this->answer( 503 );
		Sync_Queue::send_marked();
		$this->next = null;
		$before     = $this->sent;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->dispatch( 'POST', "/eventwatch/v1/events/$id/resend" );

		$this->assertSame( $before + 1, $this->sent );
		$this->assertSame( 'review', $response->get_data()['state'] );
	}

	/**
	 * 未認証の応答に同期の値が出ない。context=edit も断る。
	 */
	public function test_sync_values_are_not_public() {
		$id = $this->event();
		Sync_Queue::send_marked();
		update_post_meta( $id, Sync_Queue::NO_SEND, 1 );
		wp_set_current_user( 0 );

		$route    = '/wp/v2/' . Post_Type::POST_TYPE . "/$id";
		$response = $this->dispatch( 'GET', $route );
		$json     = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( Sync_Rest::SEND_FIELD, $response->get_data() );
		$this->assertStringNotContainsString( '_eventwatch', $json );
		$this->assertStringNotContainsString( 'pendingReview', $json );

		$this->assertSame( 401, $this->dispatch( 'GET', $route, array( 'context' => 'edit' ) )->get_status() );
	}

	/**
	 * 編集画面の文脈で読み書きできる。全部を送り返しても保存でき、切り替えだけの保存で取り下げを送る。
	 */
	public function test_editor_can_toggle_send() {
		$id = $this->event();
		Sync_Queue::send_marked();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$route = '/wp/v2/' . Post_Type::POST_TYPE . "/$id";
		$read  = $this->dispatch( 'GET', $route, array( 'context' => 'edit' ) )->get_data();
		$this->assertTrue( $read[ Sync_Rest::SEND_FIELD ] );

		$saved = $this->dispatch(
			'POST',
			$route,
			array(
				'meta'                => $read['meta'],
				Sync_Rest::SEND_FIELD => false,
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertFalse( $saved->get_data()[ Sync_Rest::SEND_FIELD ] );
		$this->assertSame( '1', get_post_meta( $id, Sync_Queue::NO_SEND, true ) );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );

		$before = $this->sent;
		Sync_Queue::send_marked();
		$this->assertSame( $before + 1, $this->sent );

		$this->dispatch( 'POST', $route, array( Sync_Rest::SEND_FIELD => true ) );
		$this->assertSame( '', get_post_meta( $id, Sync_Queue::NO_SEND, true ) );
	}
}
