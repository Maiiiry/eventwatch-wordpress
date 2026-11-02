<?php
/**
 * イベントの事実の欄（投稿のメタ）。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;
use WP_REST_Post_Meta_Fields;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メタの登録・sanitize・REST の検証と、並べ替え用の UTC の整数の更新を持つ。
 *
 * 欄と上限は EventWatch の主催者の投稿に揃える。あとで同期するときに、EventWatch の検証で弾かれないため。
 */
class Event_Meta {

	const OCCURRENCES    = 'eventwatch_occurrences';
	const ZONE           = 'eventwatch_zone';
	const SCHEDULE_NOTE  = 'eventwatch_schedule_note';
	const CATEGORY       = 'eventwatch_category';
	const PREFECTURE     = 'eventwatch_prefecture';
	const ONLINE         = 'eventwatch_online';
	const VENUE_NAME     = 'eventwatch_venue_name';
	const VENUE_ADDRESS  = 'eventwatch_venue_address';
	const URL            = 'eventwatch_url';
	const FIRST_START    = 'eventwatch_first_start_utc';
	const LAST_END       = 'eventwatch_last_end_utc';
	const MAX_RANGES     = 100;
	const MAX_NOTE       = 200;
	const MAX_VENUE_NAME = 256;
	const MAX_ADDRESS    = 512;
	const MAX_URL        = 1024;

	/**
	 * 日時の書式。文字列のまま比べて、順序が時刻の順と一致する。
	 */
	const DATETIME_FORMAT  = 'Y-m-d H:i';
	const DATETIME_PATTERN = '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$';

	/**
	 * URL の形。EventWatch の検証と同じ。
	 */
	const URL_PATTERN = '^$|^https?://\S+$';

	/**
	 * サイトで誰かに見える状態。日程が 0 組のままでは入れない。
	 *
	 * @var string[]
	 */
	const VISIBLE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		// 投稿タイプの登録（優先度 10）の後に登録する。リビジョンの対応を確かめられるため。
		add_action( 'init', array( self::class, 'register' ), 11 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		// 本体がメタを戻す処理（優先度 10）の後に計算する。
		add_action( 'wp_restore_post_revision', array( self::class, 'sync_after_restore' ), 11 );
		add_filter( 'rest_pre_insert_' . Post_Type::POST_TYPE, array( self::class, 'validate_rest_request' ), 10, 2 );
		add_filter( 'rest_prepare_' . Post_Type::POST_TYPE, array( self::class, 'hide_protected_meta' ), 10, 3 );
	}

	/**
	 * メタを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		$occurrence_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'start' ),
			'properties'           => array(
				'start' => array(
					'type'    => 'string',
					'pattern' => self::DATETIME_PATTERN,
				),
				'end'   => array(
					'type'    => 'string',
					'pattern' => '^$|' . self::DATETIME_PATTERN,
				),
			),
		);

		self::register_field(
			self::OCCURRENCES,
			'array',
			array(),
			array( self::class, 'sanitize_occurrences' ),
			array(
				'type'     => 'array',
				'maxItems' => self::MAX_RANGES,
				'items'    => $occurrence_schema,
			)
		);
		self::register_field( self::ZONE, 'string', '', array( self::class, 'sanitize_zone' ) );
		self::register_field(
			self::SCHEDULE_NOTE,
			'string',
			'',
			array( self::class, 'sanitize_schedule_note' ),
			array(
				'type'      => 'string',
				'maxLength' => self::MAX_NOTE,
			)
		);
		self::register_field(
			self::CATEGORY,
			'integer',
			0,
			array( self::class, 'sanitize_category' ),
			array(
				'type' => 'integer',
				'enum' => array_merge( array( 0 ), array_keys( Event_Options::categories() ) ),
			)
		);
		self::register_field(
			self::PREFECTURE,
			'integer',
			Event_Options::PREFECTURE_NONE,
			array( self::class, 'sanitize_prefecture' ),
			array(
				'type'    => 'integer',
				'minimum' => Event_Options::PREFECTURE_NONE,
				'maximum' => 47,
			)
		);
		self::register_field( self::ONLINE, 'boolean', false, 'rest_sanitize_boolean' );
		self::register_field(
			self::VENUE_NAME,
			'string',
			'',
			array( self::class, 'sanitize_venue_name' ),
			array(
				'type'      => 'string',
				'maxLength' => self::MAX_VENUE_NAME,
			)
		);
		self::register_field(
			self::VENUE_ADDRESS,
			'string',
			'',
			array( self::class, 'sanitize_venue_address' ),
			array(
				'type'      => 'string',
				'maxLength' => self::MAX_ADDRESS,
			)
		);
		self::register_field(
			self::URL,
			'string',
			'',
			array( self::class, 'sanitize_url' ),
			array(
				'type'      => 'string',
				'maxLength' => self::MAX_URL,
				'pattern'   => self::URL_PATTERN,
			)
		);

		// 日程から計算する値。リビジョンの復元でも計算し直すので、リビジョンには持たせない。
		foreach ( array( self::FIRST_START, self::LAST_END ) as $key ) {
			register_post_meta(
				Post_Type::POST_TYPE,
				$key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_timestamp' ),
					'auth_callback'     => '__return_false',
				)
			);
		}
	}

	/**
	 * 編集できる人だけが書ける欄を 1 つ登録する。
	 *
	 * @param string     $key      メタのキー。
	 * @param string     $type     型。
	 * @param mixed      $fallback 既定の値。
	 * @param callable   $sanitize sanitize の関数。
	 * @param array|null $schema   REST のスキーマ。
	 * @return void
	 */
	private static function register_field( $key, $type, $fallback, $sanitize, $schema = null ) {
		register_post_meta(
			Post_Type::POST_TYPE,
			$key,
			array(
				'type'              => $type,
				'single'            => true,
				'default'           => $fallback,
				'show_in_rest'      => $schema ? array( 'schema' => $schema ) : true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => array( self::class, 'can_edit' ),
				'revisions_enabled' => true,
			)
		);
	}

	/**
	 * メタを書いてよいか。
	 *
	 * @param bool   $allowed  これまでの判定。
	 * @param string $meta_key メタのキー。
	 * @param int    $post_id  投稿の ID。
	 * @param int    $user_id  ユーザーの ID。
	 * @return bool
	 */
	public static function can_edit( $allowed, $meta_key, $post_id, $user_id ) {
		return user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * 日程の組を整える。
	 *
	 * REST は validate_rest_request が先に断るので、ここに来る不正な行は update_post_meta の直呼びだけ。
	 * 例外を投げられない場所なので、形の合わない行・終了が開始より前の行・前の組と重なる行を落とす。
	 *
	 * @param mixed $value 入力。
	 * @return array<int, array{start: string, end: string}>
	 */
	public static function sanitize_occurrences( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['start'] ) || ! self::is_valid_datetime( $row['start'] ) ) {
				continue;
			}
			$end = isset( $row['end'] ) && self::is_valid_datetime( $row['end'] ) ? $row['end'] : '';
			if ( '' !== $end && $end < $row['start'] ) {
				continue;
			}
			$rows[] = array(
				'start' => $row['start'],
				'end'   => $end,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		$kept     = array();
		$last_end = '';
		foreach ( $rows as $row ) {
			if ( '' !== $last_end && $row['start'] <= $last_end ) {
				continue;
			}
			$kept[]   = $row;
			$last_end = '' !== $row['end'] ? $row['end'] : $row['start'];
		}

		return array_slice( $kept, 0, self::MAX_RANGES );
	}

	/**
	 * タイムゾーンを整える。地域の名前と +09:00 の形だけを通す。
	 *
	 * DateTimeZone が読める JST などの略号は、EventWatch が受け付けない。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_zone( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return in_array( $value, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) ? $value : '';
	}

	/**
	 * 日程の補足を整える。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_schedule_note( $value ) {
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_NOTE );
	}

	/**
	 * カテゴリを整える。
	 *
	 * @param mixed $value 入力。
	 * @return int
	 */
	public static function sanitize_category( $value ) {
		$category = (int) $value;
		return array_key_exists( $category, Event_Options::categories() ) ? $category : 0;
	}

	/**
	 * 都道府県を整える。
	 *
	 * @param mixed $value 入力。
	 * @return int
	 */
	public static function sanitize_prefecture( $value ) {
		$prefecture = (int) $value;
		return array_key_exists( $prefecture, Event_Options::prefectures() ) ? $prefecture : Event_Options::PREFECTURE_NONE;
	}

	/**
	 * 会場名を整える。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_venue_name( $value ) {
		return mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_VENUE_NAME );
	}

	/**
	 * 会場の住所を整える。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_venue_address( $value ) {
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_ADDRESS );
	}

	/**
	 * 別の公式ページの URL を整える。形が合わないもの・長すぎるものは空にする（途中で切ると別の URL になる）。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		if ( '' === $url || mb_strlen( $url ) > self::MAX_URL || ! preg_match( '#' . self::URL_PATTERN . '#', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * UTC の整数を整える。sanitize のフィルターは引数を 4 つ渡すので、intval を直接は渡せない。
	 *
	 * @param mixed $value 入力。
	 * @return int
	 */
	public static function sanitize_timestamp( $value ) {
		return (int) $value;
	}

	/**
	 * 日程の変更で、タイムゾーンと UTC の整数を更新する。
	 *
	 * @param int|int[] $meta_id メタの ID。
	 * @param int       $post_id 投稿の ID。
	 * @param string    $key     メタのキー。
	 * @return void
	 */
	public static function on_meta_change( $meta_id, $post_id, $key ) {
		if ( self::OCCURRENCES !== $key && self::ZONE !== $key ) {
			return;
		}
		// リビジョンへの写しもこのフックを通るが、リビジョンには UTC の整数を持たせない。
		if ( Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		// 戻す途中はタイムゾーンが一度消える。ここで補うと、戻す値が 2 行目になって読まれない。
		if ( doing_action( 'wp_restore_post_revision' ) ) {
			return;
		}

		self::sync_derived( (int) $post_id );
	}

	/**
	 * リビジョンからメタを戻した後に、UTC の整数を計算し直す。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function sync_after_restore( $post_id ) {
		if ( Post_Type::POST_TYPE === get_post_type( $post_id ) ) {
			self::sync_derived( (int) $post_id );
		}
	}

	/**
	 * タイムゾーンが空なら入れ、UTC の整数を計算し直す。
	 *
	 * タイムゾーンは一度入れたら自動で変えない。サイトの設定を変えても、保存済みの時刻がずれないようにするため。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	private static function sync_derived( $post_id ) {
		$rows = get_post_meta( $post_id, self::OCCURRENCES, true );

		if ( ! is_array( $rows ) || ! $rows ) {
			delete_post_meta( $post_id, self::FIRST_START );
			delete_post_meta( $post_id, self::LAST_END );
			return;
		}

		$zone = (string) get_post_meta( $post_id, self::ZONE, true );
		if ( '' === $zone ) {
			// このメタの追加でフックがもう一度呼ばれ、そちらが整数を入れる。
			update_post_meta( $post_id, self::ZONE, wp_timezone_string() );
			return;
		}

		$last  = end( $rows );
		$first = reset( $rows );
		$start = self::to_utc( $first['start'], $zone );
		$end   = self::to_utc( '' !== $last['end'] ? $last['end'] : $last['start'], $zone );

		if ( null === $start || null === $end ) {
			return;
		}

		update_post_meta( $post_id, self::FIRST_START, $start );
		update_post_meta( $post_id, self::LAST_END, $end );
	}

	/**
	 * REST の保存を、投稿を保存する前に確かめる。
	 *
	 * @param \stdClass|WP_Error $prepared_post 保存しようとしている投稿。
	 * @param WP_REST_Request    $request       要求。
	 * @return \stdClass|WP_Error
	 */
	public static function validate_rest_request( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		$post_id = isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;
		$meta    = $request->get_param( 'meta' );
		$meta    = is_array( $meta ) ? $meta : array();

		// 編集画面はメタを全部送り返す。読むだけの欄が残っていると、本体が権限の無い書き込みとして断る。
		unset( $meta[ self::FIRST_START ], $meta[ self::LAST_END ] );
		$request->set_param( 'meta', $meta );

		$error = self::find_error( $prepared_post, $meta, $post_id );
		if ( ! $error ) {
			return $prepared_post;
		}

		// 自動保存の本体は、ここの WP_Error を見ずに先へ進む。本文は残し、誤りのあるメタだけを落とす。
		if ( preg_match( '#/autosaves$#', (string) $request->get_route() ) ) {
			$request->set_param( 'meta', array() );
			return $prepared_post;
		}

		return $error;
	}

	/**
	 * 保存しようとしている内容の誤りを探す。
	 *
	 * @param \stdClass $prepared_post 保存しようとしている投稿。
	 * @param array     $meta          送られたメタ。
	 * @param int       $post_id       投稿の ID。新規なら 0。
	 * @return WP_Error|null
	 */
	private static function find_error( $prepared_post, array $meta, $post_id ) {
		$error = self::validate_against_schema( $meta );
		if ( $error ) {
			return $error;
		}

		$occurrences = array_key_exists( self::OCCURRENCES, $meta )
			? $meta[ self::OCCURRENCES ]
			: ( $post_id ? get_post_meta( $post_id, self::OCCURRENCES, true ) : array() );

		$error = self::validate_occurrences( is_array( $occurrences ) ? $occurrences : array() );
		if ( $error ) {
			return $error;
		}

		$status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : ( $post_id ? get_post_status( $post_id ) : 'draft' );
		if ( ! $occurrences && in_array( $status, self::VISIBLE_STATUSES, true ) ) {
			return self::invalid(
				'eventwatch_occurrences_required',
				__( 'Add at least one date before publishing this event.', 'eventwatch' )
			);
		}

		$name    = array_key_exists( self::VENUE_NAME, $meta ) ? $meta[ self::VENUE_NAME ] : ( $post_id ? get_post_meta( $post_id, self::VENUE_NAME, true ) : '' );
		$address = array_key_exists( self::VENUE_ADDRESS, $meta ) ? $meta[ self::VENUE_ADDRESS ] : ( $post_id ? get_post_meta( $post_id, self::VENUE_ADDRESS, true ) : '' );
		if ( self::is_blank( $name ) !== self::is_blank( $address ) ) {
			return self::invalid(
				'eventwatch_venue_incomplete',
				__( 'Enter both the venue name and the venue address, or leave both empty.', 'eventwatch' )
			);
		}

		return null;
	}

	/**
	 * パスワード保護のイベントのメタを、パスワードを知らない人の応答から外す。
	 *
	 * 本体は本文と抜粋を隠すが、メタは隠さない。ここではメタがイベントの中身そのものになる。
	 * 編集できる人には出す。隠すと編集画面がメタを読めず、次の保存で欄が空になる。
	 *
	 * @param \WP_REST_Response $response 応答。
	 * @param \WP_Post          $post     投稿。
	 * @param WP_REST_Request   $request  要求。
	 * @return \WP_REST_Response
	 */
	public static function hide_protected_meta( $response, $post, $request ) {
		if ( '' === $post->post_password || current_user_can( 'edit_post', $post->ID ) || ! post_password_required( $post ) ) {
			return $response;
		}

		$password = $request->get_param( 'password' );
		if ( is_string( $password ) && hash_equals( $post->post_password, $password ) ) {
			return $response;
		}

		$data = $response->get_data();
		unset( $data['meta'] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * 空白だけの値か。編集画面の trim と揃えて、全角空白も空白として扱う。
	 *
	 * @param mixed $value 値。
	 * @return bool
	 */
	private static function is_blank( $value ) {
		return '' === preg_replace( '/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', (string) $value );
	}

	/**
	 * 送られたメタをスキーマで確かめる。
	 *
	 * 本体はメタのスキーマを投稿を保存した後に確かめるので、そのままだと 400 を返しても投稿は作られて残る。
	 *
	 * @param array $meta 送られたメタ。
	 * @return WP_Error|null
	 */
	private static function validate_against_schema( array $meta ) {
		$fields = new WP_REST_Post_Meta_Fields( Post_Type::POST_TYPE );
		$schema = $fields->get_field_schema();

		foreach ( $schema['properties'] as $key => $field_schema ) {
			// null は本体が「既定に戻す」として扱う。
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$result = rest_validate_value_from_schema( $meta[ $key ], $field_schema, 'meta.' . $key );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
		}

		return null;
	}

	/**
	 * 日程の組の規則を確かめる。
	 *
	 * @param array $rows 日程の組。
	 * @return WP_Error|null
	 */
	private static function validate_occurrences( array $rows ) {
		$normalized = array();
		foreach ( $rows as $row ) {
			$start = is_array( $row ) && isset( $row['start'] ) ? $row['start'] : '';
			$end   = is_array( $row ) && isset( $row['end'] ) ? $row['end'] : '';

			if ( ! self::is_valid_datetime( $start ) || ( '' !== $end && ! self::is_valid_datetime( $end ) ) ) {
				return self::invalid(
					'eventwatch_occurrence_invalid',
					__( 'One of the dates does not exist. Check the dates of this event.', 'eventwatch' )
				);
			}
			if ( '' !== $end && $end < $start ) {
				return self::invalid(
					'eventwatch_occurrence_end_before_start',
					__( 'A date ends before it starts. Check the dates of this event.', 'eventwatch' )
				);
			}
			$normalized[] = array( $start, '' !== $end ? $end : $start );
		}

		sort( $normalized );
		$previous_end = '';
		foreach ( $normalized as $range ) {
			if ( '' !== $previous_end && $range[0] <= $previous_end ) {
				return self::invalid(
					'eventwatch_occurrence_overlap',
					__( 'Two dates overlap or touch. Leave a gap between them, or combine them into one date.', 'eventwatch' )
				);
			}
			$previous_end = $range[1];
		}

		return null;
	}

	/**
	 * 400 の WP_Error を作る。
	 *
	 * @param string $code    コード。
	 * @param string $message 文。
	 * @return WP_Error
	 */
	private static function invalid( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * 実在する日時の文字列か。
	 *
	 * @param mixed $value 値。
	 * @return bool
	 */
	private static function is_valid_datetime( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!' . self::DATETIME_FORMAT, $value, new DateTimeZone( 'UTC' ) );
		// 2 月 30 日は 3 月 2 日に繰り上がるので、書式で戻して元の文字列と比べる。
		return $parsed && $parsed->format( self::DATETIME_FORMAT ) === $value;
	}

	/**
	 * 壁時計の時刻を UTC の整数にする。
	 *
	 * @param string $datetime 日時。
	 * @param string $zone     タイムゾーン。
	 * @return int|null
	 */
	private static function to_utc( $datetime, $zone ) {
		try {
			$parsed = DateTimeImmutable::createFromFormat( '!' . self::DATETIME_FORMAT, $datetime, new DateTimeZone( $zone ) );
		} catch ( Exception $e ) {
			return null;
		}
		return $parsed ? $parsed->getTimestamp() : null;
	}
}
