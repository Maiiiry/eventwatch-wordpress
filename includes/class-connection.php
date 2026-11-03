<?php
/**
 * EventWatch の API キーと、接続の状態。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 接続の状態は、確かめた結果を option に持つ。設定画面を開くたびに外へ通信しないため。
 */
class Connection {

	const KEY_OPTION   = 'eventwatch_api_key';
	const STATE_OPTION = 'eventwatch_connection';
	const PATH         = '/api/v1.0/org-api/organization';

	/**
	 * 確かめるのは人が待っているので、送信より短く切る。
	 */
	const CHECK_TIMEOUT = 10;

	const UNCHECKED    = 'unchecked';
	const CONNECTED    = 'connected';
	const UNAUTHORIZED = 'unauthorized';
	const SUSPENDED    = 'suspended';
	const FORBIDDEN    = 'forbidden';
	const UNREACHABLE  = 'unreachable';

	/**
	 * 設定で固定されたキー。あれば保存した値より優先する。
	 *
	 * @return string
	 */
	public static function fixed_key() {
		$key = defined( 'EVENTWATCH_API_KEY' ) ? (string) EVENTWATCH_API_KEY : '';

		/**
		 * 保存した値より優先するキー。既定は定数 EVENTWATCH_API_KEY。
		 *
		 * @param string $key キー。
		 */
		return trim( (string) apply_filters( 'eventwatch_fixed_api_key', $key ) );
	}

	/**
	 * 使うキー。無ければ空。
	 *
	 * @return string
	 */
	public static function api_key() {
		$fixed = self::fixed_key();

		return '' !== $fixed ? $fixed : (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * 画面に出す形。主催者面の一覧と同じ接頭辞（ewk_ と 8 字）だけを残す。
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::api_key();

		if ( '' === $key ) {
			return '';
		}

		// 形を確かめない定数の値は、短いと接頭辞だけで全部が出てしまう。
		return ( strlen( $key ) > 12 ? substr( $key, 0, 12 ) : '' ) . str_repeat( '•', 20 );
	}

	/**
	 * いまの状態。確かめた後でキーが替わっていれば未確認に戻す（定数の入れ替えは保存を通らない）。
	 *
	 * @return array{status: string, organization: string, checked_at: int}
	 */
	public static function state() {
		$default = array(
			'status'       => self::UNCHECKED,
			'organization' => '',
			'checked_at'   => 0,
		);
		$state   = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) || ! isset( $state['key'] ) || ! hash_equals( self::fingerprint(), (string) $state['key'] ) ) {
			return $default;
		}

		return array(
			'status'       => isset( $state['status'] ) ? (string) $state['status'] : self::UNCHECKED,
			'organization' => isset( $state['organization'] ) ? (string) $state['organization'] : '',
			'checked_at'   => isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0,
		);
	}

	/**
	 * 接続を確かめて、結果を残す。
	 *
	 * @return array{status: string, organization: string, checked_at: int}
	 */
	public static function check() {
		$result = ( new Api_Client() )->request( 'GET', self::PATH, array( 'timeout' => self::CHECK_TIMEOUT ) );

		if ( is_wp_error( $result ) ) {
			self::save( self::status_of( $result ) );
			return self::state();
		}

		$name = is_array( $result['body'] ) && isset( $result['body']['displayName'] ) && is_string( $result['body']['displayName'] )
			? sanitize_text_field( $result['body']['displayName'] )
			: '';

		// 2xx でも団体名の無い応答は、口が違うか途中の機器の応答。
		self::save( '' === $name ? self::UNREACHABLE : self::CONNECTED, $name );

		return self::state();
	}

	/**
	 * どの要求の結果でも、キーの問題は状態に残す。同期が受けた失効も画面に出すため。
	 *
	 * 一時的な失敗では状態を変えない。つながっていた事実まで消さない。
	 *
	 * @param array|WP_Error $result クライアントの戻り。
	 * @return void
	 */
	public static function observe( $result ) {
		if ( is_wp_error( $result ) ) {
			if ( in_array( $result->get_error_code(), array( Api_Client::UNAUTHORIZED, Api_Client::SUSPENDED ), true ) ) {
				self::save( self::status_of( $result ) );
			}
			return;
		}

		// 通ったので、前の失敗の案内を残さない。つながっている状態と団体名は消さない。
		if ( ! in_array( self::state()['status'], array( self::UNCHECKED, self::CONNECTED ), true ) ) {
			self::save( self::UNCHECKED );
		}
	}

	/**
	 * 状態を消す。
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * 失敗の種類を状態にする。
	 *
	 * @param WP_Error $error 失敗。
	 * @return string
	 */
	private static function status_of( WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case Api_Client::UNAUTHORIZED:
				return self::UNAUTHORIZED;
			case Api_Client::SUSPENDED:
				return self::SUSPENDED;
			case Api_Client::FORBIDDEN:
				return self::FORBIDDEN;
			case Api_Client::NO_KEY:
				return self::UNCHECKED;
		}

		return self::UNREACHABLE;
	}

	/**
	 * 状態を保存する。
	 *
	 * @param string $status       状態。
	 * @param string $organization 団体名。
	 * @return void
	 */
	private static function save( $status, $organization = '' ) {
		update_option(
			self::STATE_OPTION,
			array(
				'status'       => $status,
				'organization' => $organization,
				'checked_at'   => time(),
				'key'          => self::fingerprint(),
			),
			false
		);
	}

	/**
	 * どのキーの結果かを見分ける値。キーそのものは持たない。
	 *
	 * @return string
	 */
	public static function fingerprint() {
		return wp_hash( self::api_key() );
	}
}
