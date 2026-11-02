<?php
/**
 * イベントのメタのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Capabilities;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Event_Meta のテスト。
 */
class Event_Meta_Test extends WP_UnitTestCase {

	/**
	 * テストのたびに編集者でログインする。
	 */
	public function set_up() {
		parent::set_up();
		// 基底クラスの tear_down がメタの登録を全部消すので、テストごとに登録する。
		Event_Meta::register();
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 下書きのイベントを作る。
	 *
	 * @return int
	 */
	private function create_event() {
		return self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * REST で保存する。
	 *
	 * @param array    $params  本文。
	 * @param int|null $post_id 更新する投稿。
	 * @return \WP_REST_Response
	 */
	private function save( array $params, $post_id = null ) {
		$route   = '/wp/v2/' . Post_Type::POST_TYPE . ( $post_id ? '/' . $post_id : '' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( array_merge( array( 'title' => 'Event' ), $params ) );
		return rest_do_request( $request );
	}

	/**
	 * 1 組の日程。
	 *
	 * @param string $start 開始。
	 * @param string $end   終了。
	 * @return array{start: string, end: string}
	 */
	private function range( $start, $end = '' ) {
		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * 複数の日程を保存でき、REST で開始の順に読める。未ログインでも公開のイベントなら読める。
	 */
	public function test_multiple_dates_round_trip_through_rest() {
		$response = $this->save(
			array(
				'status' => 'publish',
				'meta'   => array(
					Event_Meta::OCCURRENCES => array(
						$this->range( '2026-12-02 10:00', '2026-12-02 12:00' ),
						$this->range( '2026-12-01 10:00' ),
					),
				),
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		wp_set_current_user( 0 );
		$read = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $id ) );

		$this->assertSame( 200, $read->get_status() );
		$this->assertSame(
			array(
				$this->range( '2026-12-01 10:00' ),
				$this->range( '2026-12-02 10:00', '2026-12-02 12:00' ),
			),
			$read->get_data()['meta'][ Event_Meta::OCCURRENCES ]
		);
		$this->assertSame( 'Asia/Tokyo', $read->get_data()['meta'][ Event_Meta::ZONE ] );
	}

	/**
	 * スキーマに合わないメタで断った作成では、投稿が残らない。
	 */
	public function test_rejected_create_leaves_no_post() {
		$before = wp_count_posts( Post_Type::POST_TYPE )->draft;

		$response = $this->save( array( 'meta' => array( Event_Meta::CATEGORY => 9 ) ) );

		$this->assertSame( 400, $response->get_status() );
		wp_cache_flush();
		$this->assertSame( $before, wp_count_posts( Post_Type::POST_TYPE )->draft );
	}

	/**
	 * 100 組までは保存でき、101 組は 400。
	 */
	public function test_range_count_limit() {
		$rows = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$rows[] = $this->range( gmdate( 'Y-m-d H:i', strtotime( '2027-01-01 00:00 UTC' ) + $i * DAY_IN_SECONDS ) );
		}
		$this->assertSame( 201, $this->save( array( 'meta' => array( Event_Meta::OCCURRENCES => $rows ) ) )->get_status() );

		$rows[] = $this->range( '2028-01-01 00:00' );
		$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::OCCURRENCES => $rows ) ) )->get_status() );
	}

	/**
	 * 日程の規則に合わないものは 400。
	 *
	 * @dataProvider data_invalid_occurrences
	 *
	 * @param array  $rows 日程。
	 * @param string $code エラーのコード。
	 */
	public function test_invalid_occurrences_are_rejected( array $rows, $code ) {
		$response = $this->save( array( 'meta' => array( Event_Meta::OCCURRENCES => $rows ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->as_error()->get_error_code() );
	}

	/**
	 * 規則に合わない日程。
	 *
	 * @return array<string, array{array, string}>
	 */
	public function data_invalid_occurrences() {
		$row = static function ( $start, $end = '' ) {
			return array(
				'start' => $start,
				'end'   => $end,
			);
		};
		return array(
			'書式が違う'        => array( array( $row( '2026/12/01 10:00' ) ), 'rest_invalid_pattern' ),
			'秒がある'         => array( array( $row( '2026-12-01 10:00:00' ) ), 'rest_invalid_pattern' ),
			'開始が無い'        => array( array( array( 'end' => '2026-12-01 10:00' ) ), 'rest_property_required' ),
			'余分なキー'        => array( array( array_merge( $row( '2026-12-01 10:00' ), array( 'x' => '1' ) ) ), 'rest_additional_properties_forbidden' ),
			'存在しない日'       => array( array( $row( '2026-02-30 10:00' ) ), 'eventwatch_occurrence_invalid' ),
			'存在しない時刻'      => array( array( $row( '2026-02-01 24:00' ) ), 'eventwatch_occurrence_invalid' ),
			'終了が開始より前'     => array( array( $row( '2026-12-01 10:00', '2026-12-01 09:59' ) ), 'eventwatch_occurrence_end_before_start' ),
			'重なる'          => array( array( $row( '2026-12-01 10:00', '2026-12-01 12:00' ), $row( '2026-12-01 11:00', '2026-12-01 13:00' ) ), 'eventwatch_occurrence_overlap' ),
			'接する'          => array( array( $row( '2026-12-01 10:00', '2026-12-01 12:00' ), $row( '2026-12-01 12:00', '2026-12-01 13:00' ) ), 'eventwatch_occurrence_overlap' ),
			'順が逆でも重なりを見る'  => array( array( $row( '2026-12-01 11:00', '2026-12-01 13:00' ), $row( '2026-12-01 10:00', '2026-12-01 12:00' ) ), 'eventwatch_occurrence_overlap' ),
			'終了が空の組は開始と同じ' => array( array( $row( '2026-12-01 10:00' ), $row( '2026-12-01 10:00', '2026-12-01 11:00' ) ), 'eventwatch_occurrence_overlap' ),
		);
	}

	/**
	 * 終了と開始が同じ組と、1 分空けた組は通る。
	 */
	public function test_boundary_occurrences_are_accepted() {
		$response = $this->save(
			array(
				'meta' => array(
					Event_Meta::OCCURRENCES => array(
						$this->range( '2026-12-01 10:00', '2026-12-01 10:00' ),
						$this->range( '2026-12-01 10:01' ),
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * 日程が 0 組なら下書きにはでき、見える状態にはできない。
	 */
	public function test_visible_status_requires_a_date() {
		$this->assertSame( 201, $this->save( array( 'status' => 'draft' ) )->get_status() );

		foreach ( array( 'publish', 'private' ) as $status ) {
			$response = $this->save( array( 'status' => $status ) );
			$this->assertSame( 400, $response->get_status(), $status );
			$this->assertSame( 'eventwatch_occurrences_required', $response->as_error()->get_error_code(), $status );
		}

		$future = $this->save(
			array(
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 400, $future->get_status() );
	}

	/**
	 * 保存済みの日程があれば、メタを送らずに公開できる。公開中に日程を空にはできない。
	 */
	public function test_visible_status_reads_stored_dates() {
		$post_id = $this->create_event();
		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-01 10:00' ) ) );

		$this->assertSame( 200, $this->save( array( 'status' => 'publish' ), $post_id )->get_status() );
		$this->assertSame( 200, $this->save( array( 'title' => 'Renamed' ), $post_id )->get_status() );

		$this->assertSame(
			400,
			$this->save( array( 'meta' => array( Event_Meta::OCCURRENCES => array() ) ), $post_id )->get_status()
		);
	}

	/**
	 * 会場名と住所は対で入れる。保存済みの値とも組み合わせて見る。
	 */
	public function test_venue_must_be_a_pair() {
		$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::VENUE_NAME => 'Hall' ) ) )->get_status() );
		$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::VENUE_ADDRESS => 'Address' ) ) )->get_status() );

		$both = $this->save(
			array(
				'meta' => array(
					Event_Meta::VENUE_NAME    => 'Hall',
					Event_Meta::VENUE_ADDRESS => 'Address',
				),
			)
		);
		$this->assertSame( 201, $both->get_status() );

		$this->assertSame(
			400,
			$this->save( array( 'meta' => array( Event_Meta::VENUE_ADDRESS => '' ) ), $both->get_data()['id'] )->get_status()
		);
	}

	/**
	 * 欄の上限は境界の値まで通り、1 字超えると 400。
	 *
	 * @dataProvider data_length_limits
	 *
	 * @param string $key   メタのキー。
	 * @param int    $limit 上限。
	 */
	public function test_length_limits( $key, $limit ) {
		$pair = Event_Meta::VENUE_NAME === $key ? array( Event_Meta::VENUE_ADDRESS => 'x' ) : array();
		if ( Event_Meta::VENUE_ADDRESS === $key ) {
			$pair = array( Event_Meta::VENUE_NAME => 'x' );
		}

		$ok = $this->save( array( 'meta' => array_merge( $pair, array( $key => str_repeat( 'あ', $limit ) ) ) ) );
		$this->assertSame( 201, $ok->get_status() );
		$this->assertSame( str_repeat( 'あ', $limit ), get_post_meta( $ok->get_data()['id'], $key, true ) );

		$over = $this->save( array( 'meta' => array_merge( $pair, array( $key => str_repeat( 'あ', $limit + 1 ) ) ) ) );
		$this->assertSame( 400, $over->get_status() );
	}

	/**
	 * 字数の上限がある欄。
	 *
	 * @return array<string, array{string, int}>
	 */
	public function data_length_limits() {
		return array(
			'日程の補足' => array( 'eventwatch_schedule_note', 200 ),
			'会場名'   => array( 'eventwatch_venue_name', 256 ),
			'住所'    => array( 'eventwatch_venue_address', 512 ),
		);
	}

	/**
	 * URL の形と上限。
	 */
	public function test_url_rules() {
		$prefix = 'https://example.com/';

		$ok = $this->save( array( 'meta' => array( Event_Meta::URL => $prefix . str_repeat( 'a', 1024 - strlen( $prefix ) ) ) ) );
		$this->assertSame( 201, $ok->get_status() );

		foreach ( array( $prefix . str_repeat( 'a', 1025 - strlen( $prefix ) ), 'ftp://example.com', 'example.com', 'javascript:alert(1)' ) as $url ) {
			$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::URL => $url ) ) )->get_status(), $url );
		}
	}

	/**
	 * カテゴリ・都道府県の値の範囲。
	 */
	public function test_category_and_prefecture_ranges() {
		foreach ( array( 0, 1, 8, 255 ) as $category ) {
			$this->assertSame( 201, $this->save( array( 'meta' => array( Event_Meta::CATEGORY => $category ) ) )->get_status(), (string) $category );
		}
		foreach ( array( -1, 9, 254, 256 ) as $category ) {
			$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::CATEGORY => $category ) ) )->get_status(), (string) $category );
		}
		foreach ( array( 0, 1, 47 ) as $prefecture ) {
			$this->assertSame( 201, $this->save( array( 'meta' => array( Event_Meta::PREFECTURE => $prefecture ) ) )->get_status(), (string) $prefecture );
		}
		foreach ( array( -1, 48 ) as $prefecture ) {
			$this->assertSame( 400, $this->save( array( 'meta' => array( Event_Meta::PREFECTURE => $prefecture ) ) )->get_status(), (string) $prefecture );
		}
	}

	/**
	 * update_post_meta の直呼びでは、sanitize が不正な値を落とす。
	 */
	public function test_sanitize_on_direct_update() {
		$post_id = $this->create_event();

		update_post_meta(
			$post_id,
			Event_Meta::OCCURRENCES,
			array(
				$this->range( '2026-12-03 10:00' ),
				$this->range( 'not a date' ),
				'scalar',
				$this->range( '2026-02-30 10:00' ),
				$this->range( '2026-12-02 10:00', '2026-12-01 10:00' ),
				$this->range( '2026-12-01 10:00', '2026-12-01 12:00' ),
				$this->range( '2026-12-01 11:00' ),
				array(
					'start' => '2026-12-04 10:00',
					'end'   => 'bad',
				),
			)
		);
		$this->assertSame(
			array(
				$this->range( '2026-12-01 10:00', '2026-12-01 12:00' ),
				$this->range( '2026-12-03 10:00' ),
				$this->range( '2026-12-04 10:00' ),
			),
			get_post_meta( $post_id, Event_Meta::OCCURRENCES, true )
		);

		update_post_meta( $post_id, Event_Meta::SCHEDULE_NOTE, "<b>a</b>\nb" . str_repeat( 'x', 300 ) );
		$note = get_post_meta( $post_id, Event_Meta::SCHEDULE_NOTE, true );
		$this->assertStringNotContainsString( '<b>', $note );
		$this->assertSame( 200, mb_strlen( $note ) );

		update_post_meta( $post_id, Event_Meta::VENUE_NAME, '<script>x</script>Hall' );
		$this->assertSame( 'Hall', get_post_meta( $post_id, Event_Meta::VENUE_NAME, true ) );

		update_post_meta( $post_id, Event_Meta::URL, 'javascript:alert(1)' );
		$this->assertSame( '', get_post_meta( $post_id, Event_Meta::URL, true ) );

		update_post_meta( $post_id, Event_Meta::CATEGORY, 9 );
		$this->assertSame( 0, (int) get_post_meta( $post_id, Event_Meta::CATEGORY, true ) );

		update_post_meta( $post_id, Event_Meta::PREFECTURE, 48 );
		$this->assertSame( 0, (int) get_post_meta( $post_id, Event_Meta::PREFECTURE, true ) );
	}

	/**
	 * タイムゾーンは地域の名前とオフセットだけを通す。
	 */
	public function test_sanitize_zone() {
		$this->assertSame( 'Asia/Tokyo', Event_Meta::sanitize_zone( 'Asia/Tokyo' ) );
		$this->assertSame( '+09:00', Event_Meta::sanitize_zone( '+09:00' ) );
		$this->assertSame( 'UTC', Event_Meta::sanitize_zone( 'UTC' ) );
		$this->assertSame( '', Event_Meta::sanitize_zone( 'JST' ) );
		$this->assertSame( '', Event_Meta::sanitize_zone( 'Mars/Base' ) );
		$this->assertSame( '', Event_Meta::sanitize_zone( array() ) );
	}

	/**
	 * 日程を保存するたびに、UTC の整数が更新される。
	 */
	public function test_utc_values_follow_every_save() {
		$post_id = $this->create_event();

		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-01 09:00', '2026-12-01 18:00' ) ) );
		$this->assertSame( strtotime( '2026-12-01 00:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true ) );
		$this->assertSame( strtotime( '2026-12-01 09:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::LAST_END, true ) );

		update_post_meta(
			$post_id,
			Event_Meta::OCCURRENCES,
			array(
				$this->range( '2026-12-05 09:00', '2026-12-05 18:00' ),
				$this->range( '2026-11-30 09:00' ),
			)
		);
		$this->assertSame( strtotime( '2026-11-30 00:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true ) );
		$this->assertSame( strtotime( '2026-12-05 09:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::LAST_END, true ) );

		// 最後の組の終了が空なら、最後の組の開始が終了になる。
		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-05 09:00' ) ) );
		$this->assertSame( strtotime( '2026-12-05 00:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::LAST_END, true ) );

		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array() );
		$this->assertFalse( metadata_exists( 'post', $post_id, Event_Meta::FIRST_START ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Event_Meta::LAST_END ) );
	}

	/**
	 * オフセットの指定のサイトでは +09:00 の形で持ち、夏時間のある地域はその日の時差で計算する。
	 */
	public function test_utc_values_for_offset_and_dst() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 9 );
		$offset_post = $this->create_event();
		update_post_meta( $offset_post, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-01 09:00' ) ) );
		$this->assertSame( '+09:00', get_post_meta( $offset_post, Event_Meta::ZONE, true ) );
		$this->assertSame( strtotime( '2026-12-01 00:00 UTC' ), (int) get_post_meta( $offset_post, Event_Meta::FIRST_START, true ) );

		update_option( 'timezone_string', 'America/New_York' );
		$dst_post = $this->create_event();
		update_post_meta(
			$dst_post,
			Event_Meta::OCCURRENCES,
			array(
				$this->range( '2026-07-01 12:00' ),
				$this->range( '2026-12-01 12:00' ),
			)
		);
		$this->assertSame( strtotime( '2026-07-01 16:00 UTC' ), (int) get_post_meta( $dst_post, Event_Meta::FIRST_START, true ) );
		$this->assertSame( strtotime( '2026-12-01 17:00 UTC' ), (int) get_post_meta( $dst_post, Event_Meta::LAST_END, true ) );
	}

	/**
	 * サイトのタイムゾーンを変えても、保存済みの時刻はずれず、日程を直しても元のタイムゾーンで読む。
	 */
	public function test_changing_site_timezone_does_not_shift_events() {
		$post_id = $this->create_event();
		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-01 09:00' ) ) );
		$before = (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true );

		update_option( 'timezone_string', 'America/New_York' );

		$this->assertSame( 'Asia/Tokyo', get_post_meta( $post_id, Event_Meta::ZONE, true ) );
		$this->assertSame( $before, (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true ) );

		update_post_meta(
			$post_id,
			Event_Meta::OCCURRENCES,
			array(
				$this->range( '2026-12-01 09:00' ),
				$this->range( '2026-12-02 09:00' ),
			)
		);

		$this->assertSame( 'Asia/Tokyo', get_post_meta( $post_id, Event_Meta::ZONE, true ) );
		$this->assertSame( $before, (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true ) );
	}

	/**
	 * タイムゾーンを直接変えると、UTC の整数が計算し直される。
	 */
	public function test_explicit_zone_change_recalculates() {
		$post_id = $this->create_event();
		update_post_meta( $post_id, Event_Meta::OCCURRENCES, array( $this->range( '2026-12-01 09:00' ) ) );

		update_post_meta( $post_id, Event_Meta::ZONE, 'UTC' );

		$this->assertSame( strtotime( '2026-12-01 09:00 UTC' ), (int) get_post_meta( $post_id, Event_Meta::FIRST_START, true ) );
	}

	/**
	 * UTC の整数は REST で読めて、書いても無視される。編集画面はメタを全部送り返すので、断らない。
	 */
	public function test_utc_values_are_read_only_over_rest() {
		$created = $this->save(
			array(
				'meta' => array(
					Event_Meta::OCCURRENCES => array( $this->range( '2026-12-01 09:00' ) ),
					Event_Meta::FIRST_START => 0,
					Event_Meta::LAST_END    => 0,
				),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertSame( strtotime( '2026-12-01 00:00 UTC' ), $created->get_data()['meta'][ Event_Meta::FIRST_START ] );

		$response = $this->save( array( 'meta' => array( Event_Meta::FIRST_START => 1 ) ), $id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( strtotime( '2026-12-01 00:00 UTC' ), (int) get_post_meta( $id, Event_Meta::FIRST_START, true ) );
	}

	/**
	 * 編集画面と同じように、読んだメタを全部送り返しても保存できる。
	 */
	public function test_saving_back_all_meta_like_the_editor() {
		$created = $this->save( array( 'meta' => array( Event_Meta::OCCURRENCES => array( $this->range( '2026-12-01 09:00' ) ) ) ) );
		$id      = $created->get_data()['id'];

		$meta                            = $created->get_data()['meta'];
		$meta[ Event_Meta::OCCURRENCES ] = array( $this->range( '2026-12-20 09:00' ) );

		$response = $this->save(
			array(
				'status' => 'publish',
				'meta'   => $meta,
			),
			$id
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertSame( strtotime( '2026-12-20 00:00 UTC' ), (int) get_post_meta( $id, Event_Meta::FIRST_START, true ) );
	}

	/**
	 * リビジョンから戻すと、日程と UTC の整数も戻る。
	 */
	public function test_restoring_a_revision_restores_dates_and_utc_values() {
		$id = $this->save( array( 'content' => 'zero' ) )->get_data()['id'];
		// 作成のときにはリビジョンができない。最初の更新から残る。
		$this->save(
			array(
				'content' => 'one',
				'meta'    => array( Event_Meta::OCCURRENCES => array( $this->range( '2026-12-01 09:00' ) ) ),
			),
			$id
		);
		$this->save(
			array(
				'content' => 'two',
				'meta'    => array( Event_Meta::OCCURRENCES => array( $this->range( '2026-12-20 09:00' ) ) ),
			),
			$id
		);

		$old = null;
		foreach ( wp_get_post_revisions( $id ) as $revision ) {
			if ( 'one' === $revision->post_content ) {
				$old = $revision;
			}
		}
		$this->assertNotNull( $old );

		wp_restore_post_revision( $old->ID );

		$this->assertSame( array( $this->range( '2026-12-01 09:00' ) ), get_post_meta( $id, Event_Meta::OCCURRENCES, true ) );
		$this->assertSame( strtotime( '2026-12-01 00:00 UTC' ), (int) get_post_meta( $id, Event_Meta::FIRST_START, true ) );
		$this->assertFalse( metadata_exists( 'post', $old->ID, Event_Meta::FIRST_START ) );
	}

	/**
	 * 作成から削除まで、EventWatch へ通信しない。
	 */
	public function test_no_requests_to_eventwatch() {
		$hosts = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$hosts ) {
				$hosts[] = wp_parse_url( $url, PHP_URL_HOST );
				return $preempt;
			},
			10,
			3
		);

		$created = $this->save(
			array(
				'status' => 'publish',
				'meta'   => array( Event_Meta::OCCURRENCES => array( $this->range( '2026-12-01 09:00' ) ) ),
			)
		);
		$id      = $created->get_data()['id'];
		$this->save( array( 'title' => 'Renamed' ), $id );
		$this->save( array( 'status' => 'private' ), $id );
		$this->save( array( 'status' => 'draft' ), $id );
		wp_trash_post( $id );
		wp_delete_post( $id, true );

		$eventwatch = array_filter(
			$hosts,
			static function ( $host ) {
				return false !== strpos( (string) $host, 'eventwatch' );
			}
		);
		$this->assertSame( array(), $eventwatch );
	}

	/**
	 * リビジョンにも欄が残る設定になっている。UTC の整数は残さない。
	 */
	public function test_revision_settings() {
		$fields = wp_post_revision_meta_keys( Post_Type::POST_TYPE );

		$this->assertContains( Event_Meta::OCCURRENCES, $fields );
		$this->assertContains( Event_Meta::ZONE, $fields );
		$this->assertNotContains( Event_Meta::FIRST_START, $fields );
	}
}
