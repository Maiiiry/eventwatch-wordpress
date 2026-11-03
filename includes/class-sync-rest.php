<?php
/**
 * 編集画面が読む、送信の状態。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 同期のメタは REST のメタの一覧に載せない。編集できる人だけが、ここの口と edit の文脈の欄で読み書きする。
 */
class Sync_Rest {

	const REST_NAMESPACE = 'eventwatch/v1';
	const SEND_FIELD     = 'eventwatch_send';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * 欄と口を登録する。
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_field(
			Post_Type::POST_TYPE,
			self::SEND_FIELD,
			array(
				'get_callback'    => static function ( $post ) {
					return ! get_post_meta( (int) $post['id'], Sync_Queue::NO_SEND, true );
				},
				'update_callback' => static function ( $value, $post ) {
					if ( $value ) {
						delete_post_meta( $post->ID, Sync_Queue::NO_SEND );
					} else {
						update_post_meta( $post->ID, Sync_Queue::NO_SEND, 1 );
					}
				},
				'schema'          => array(
					'type'    => 'boolean',
					// 未認証の応答に載せない。edit の文脈は本体が編集の権限を求める。
					'context' => array( 'edit' ),
				),
			)
		);

		$args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_status' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'resend' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * そのイベントを編集できるか。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return bool
	 */
	public static function can_edit( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];

		return Post_Type::POST_TYPE === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * 状態を返す。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function read_status( WP_REST_Request $request ) {
		return new WP_REST_Response( self::status( (int) $request['id'] ) );
	}

	/**
	 * 今すぐ送り直して、状態を返す。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function resend( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		Sync_Queue::resend( $post_id );

		return new WP_REST_Response( self::status( $post_id ) );
	}

	/**
	 * 編集画面に出す状態。文はここで作り、画面は描くだけにする。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return array
	 */
	public static function status( $post_id ) {
		$state   = self::state( $post_id );
		$text    = self::state_text();
		$error   = get_post_meta( $post_id, Sync_Queue::ERROR, true );
		$blocks  = get_post_meta( $post_id, Sync_Queue::BLOCKS, true );
		$remote  = get_post_meta( $post_id, Sync_Queue::REMOTE, true );
		$next_at = (int) get_post_meta( $post_id, Sync_Queue::NEXT_AT, true );
		$sent_at = (int) get_post_meta( $post_id, Sync_Queue::SENT_AT, true );
		$reason  = array(
			'label' => '',
			'text'  => '',
		);
		$meta    = array(
			'label' => '',
			'value' => '',
		);

		if ( 'failed' === $state && is_array( $error ) ) {
			$reason = array(
				'label' => __( 'Reason', 'eventwatch' ),
				'text'  => self::error_text( $error ),
			);
			if ( $next_at > time() ) {
				$meta = array(
					'label' => __( 'Next retry', 'eventwatch' ),
					'value' => self::format_time( $next_at ),
				);
			}
		} elseif ( 'fix' === $state && is_array( $remote ) && '' !== $remote['rejectionReason'] ) {
			$reason = array(
				'label' => __( 'Reason from EventWatch', 'eventwatch' ),
				'text'  => $remote['rejectionReason'],
			);
		}

		if ( '' === $meta['label'] && $sent_at > 0 && in_array( $state, array( 'review', 'live', 'fix', 'stopped', 'withdrawn' ), true ) ) {
			$meta = array(
				'label' => __( 'Last sent', 'eventwatch' ),
				'value' => self::format_time( $sent_at ),
			);
		}

		return array(
			'state'       => $state,
			'label'       => $text[ $state ][0],
			'description' => $text[ $state ][1],
			'reason'      => $reason,
			'meta'        => $meta,
			// 印が残っているものだけ。送り直しても変わらない失敗は、内容を直して更新する。
			'canResend'   => 'failed' === $state && '' !== (string) get_post_meta( $post_id, Sync_Queue::PENDING, true ),
			'blocks'      => 'off' !== $state && is_array( $blocks ) ? array_map( array( self::class, 'block_item' ), $blocks ) : array(),
		);
	}

	/**
	 * 切り替えだけを変えた、まだ保存していない間に出す文。
	 *
	 * @return array{off: array{label: string, description: string}, unsent: array{label: string, description: string}}
	 */
	public static function unsaved_text() {
		$text = self::state_text();

		return array(
			'off'    => array(
				'label'       => $text['off'][0],
				'description' => $text['off'][1],
			),
			'unsent' => array(
				'label'       => $text['unsent'][0],
				'description' => $text['unsent'][1],
			),
		);
	}

	/**
	 * 状態の種類を決める。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function state( $post_id ) {
		if ( get_post_meta( $post_id, Sync_Queue::NO_SEND, true ) ) {
			return 'off';
		}
		if ( get_post_meta( $post_id, Sync_Queue::ERROR, true ) ) {
			return 'failed';
		}
		if ( get_post_meta( $post_id, Sync_Queue::PENDING, true ) ) {
			return 'queued';
		}

		$remote = get_post_meta( $post_id, Sync_Queue::REMOTE, true );
		if ( ! is_array( $remote ) ) {
			return 'unsent';
		}
		if ( $remote['suspended'] ) {
			return 'stopped';
		}

		switch ( $remote['status'] ) {
			case 'pendingReview':
				return 'review';
			case 'rejected':
				return 'fix';
			case 'published':
				return 'live';
			case 'draft':
			case 'private':
				return 'withdrawn';
		}

		return 'unsent';
	}

	/**
	 * 状態の語と説明。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function state_text() {
		return array(
			'unsent'    => array( __( 'Not sent', 'eventwatch' ), __( 'This post hasn’t been sent to EventWatch yet. It will be sent automatically when you publish.', 'eventwatch' ) ),
			'queued'    => array( __( 'Waiting to send', 'eventwatch' ), __( 'This post will be sent to EventWatch shortly, usually within a few minutes.', 'eventwatch' ) ),
			'review'    => array( __( 'Under review', 'eventwatch' ), __( 'EventWatch is checking the details. Once it’s listed, you’ll see it here.', 'eventwatch' ) ),
			'live'      => array( __( 'Listed', 'eventwatch' ), __( 'This post is listed on EventWatch. Updates to the post are reflected there too.', 'eventwatch' ) ),
			'fix'       => array( __( 'Changes requested', 'eventwatch' ), __( 'EventWatch has asked for changes. Fix the post and update it to send it again.', 'eventwatch' ) ),
			'stopped'   => array( __( 'Listing paused', 'eventwatch' ), __( 'EventWatch has paused this listing. It stays published on your site.', 'eventwatch' ) ),
			'withdrawn' => array( __( 'Withdrawn from EventWatch', 'eventwatch' ), __( 'This post isn’t public on EventWatch because it isn’t published on your site. Publish it to list it again.', 'eventwatch' ) ),
			'failed'    => array( __( 'Couldn’t send', 'eventwatch' ), __( 'This post couldn’t be sent to EventWatch.', 'eventwatch' ) ),
			'off'       => array( __( 'Not sending to EventWatch', 'eventwatch' ), __( 'This post won’t be sent to EventWatch. Turn sending on, and it will be sent the next time you update the post.', 'eventwatch' ) ),
		);
	}

	/**
	 * 失敗の理由の文。原因と次の一手を書く。
	 *
	 * @param array $error Sync_Queue の記録。
	 * @return string
	 */
	private static function error_text( array $error ) {
		switch ( $error['code'] ) {
			case Api_Client::UNAUTHORIZED:
				return Connection_Admin::status_text( array( 'status' => Connection::UNAUTHORIZED ) );
			case Api_Client::SUSPENDED:
				return Connection_Admin::status_text( array( 'status' => Connection::SUSPENDED ) );
			case Api_Client::FORBIDDEN:
				return Connection_Admin::status_text( array( 'status' => Connection::FORBIDDEN ) );
			case Api_Client::NO_KEY:
				return __( 'No EventWatch API key is set. Enter a key in Settings › EventWatch, and sending resumes.', 'eventwatch' );
			case Api_Client::SITE_CHANGED:
				return __( 'This site’s URL has changed, so sending is stopped. Choose whether this site is a copy or has moved in Settings › EventWatch.', 'eventwatch' );
			case Api_Client::TEMPORARY:
			case Api_Client::RATE_LIMITED:
				return __( 'Couldn’t reach EventWatch. Your site isn’t affected. Sending is retried automatically.', 'eventwatch' );
		}

		if ( 'eventDeleted' === $error['apiCode'] ) {
			return __( 'This event was deleted on EventWatch, so it can’t be sent again. Contact EventWatch if you think this is a mistake.', 'eventwatch' );
		}

		if ( 409 === $error['status'] ) {
			return __( 'EventWatch already has another listing with this official page. If it’s the same event, contact EventWatch. Otherwise, enter a separate official page and update the post.', 'eventwatch' );
		}

		return __( 'EventWatch didn’t accept the details of this event. Check the event details and update the post.', 'eventwatch' );
	}

	/**
	 * 送れない理由の 1 行。
	 *
	 * @param array $block Sync_Payload の理由。
	 * @return array{code: string, message: string, action: string, field: string}
	 */
	private static function block_item( array $block ) {
		$action = '';
		$field  = '';

		switch ( $block['code'] ) {
			case Sync_Payload::BLOCK_CATEGORY:
				$message = __( 'No EventWatch category is selected.', 'eventwatch' );
				$action  = __( 'Choose a category', 'eventwatch' );
				$field   = 'eventwatch-field-category';
				break;
			case Sync_Payload::BLOCK_PREFECTURE:
				$message = __( 'No prefecture is selected. EventWatch needs one unless the event is held online.', 'eventwatch' );
				$action  = __( 'Choose a prefecture', 'eventwatch' );
				$field   = 'eventwatch-field-prefecture';
				break;
			case Sync_Payload::BLOCK_OCCURRENCES:
				$message = __( 'This event has no dates. Add at least one date.', 'eventwatch' );
				break;
			case Sync_Payload::BLOCK_TITLE_EMPTY:
				$message = __( 'This event has no title. Add a title.', 'eventwatch' );
				break;
			case Sync_Payload::BLOCK_TITLE_TOO_LONG:
				$message = sprintf(
					/* translators: %s: the character limit. */
					__( 'The title is too long. EventWatch accepts up to %s characters. Shorten the title.', 'eventwatch' ),
					number_format_i18n( Sync_Payload::MAX_TITLE )
				);
				break;
			default:
				$message = sprintf(
					/* translators: 1: the length of the content, 2: the character limit. */
					__( 'The content has %1$s characters. EventWatch accepts up to %2$s characters. Shorten the content.', 'eventwatch' ),
					number_format_i18n( isset( $block['length'] ) ? (int) $block['length'] : 0 ),
					number_format_i18n( Sync_Payload::MAX_DESCRIPTION )
				);
		}

		return array(
			'code'    => $block['code'],
			'message' => $message,
			'action'  => $action,
			'field'   => $field,
		);
	}

	/**
	 * 時刻の書式。
	 *
	 * @param int $timestamp UNIX 時刻。
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
