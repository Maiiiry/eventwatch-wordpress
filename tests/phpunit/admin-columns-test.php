<?php
/**
 * 一覧の画面の列のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Admin_Columns;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use WP_Query;
use WP_UnitTestCase;

/**
 * Admin_Columns のテスト。
 */
class Admin_Columns_Test extends WP_UnitTestCase {

	/**
	 * 基底クラスの tear_down がメタの登録を全部消すので、テストごとに登録する。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
	}

	/**
	 * 日程を持つイベントを作る。
	 *
	 * @param array $rows 日程。
	 * @return int
	 */
	private function create_event( array $rows = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		if ( $rows ) {
			update_post_meta( $post_id, Event_Meta::OCCURRENCES, $rows );
		}
		return $post_id;
	}

	/**
	 * 列の中身を文字列で得る。
	 *
	 * @param string $column  列。
	 * @param int    $post_id 投稿の ID。
	 * @return string
	 */
	private function render( $column, $post_id ) {
		ob_start();
		Admin_Columns::render_column( $column, $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * タイトルの直後に 2 つの列が入り、ほかの列は残る。
	 */
	public function test_columns_follow_the_title() {
		$columns = Admin_Columns::add_columns(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', Admin_Columns::COLUMN_DATE, Admin_Columns::COLUMN_PREFECTURE, 'date' ),
			array_keys( $columns )
		);
	}

	/**
	 * 開催日の列は、イベントのタイムゾーンの最初の開始と、残りの組数を出す。
	 */
	public function test_date_column() {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$single   = $this->create_event( array( array( 'start' => '2026-12-01 09:00' ) ) );
		$multiple = $this->create_event(
			array(
				array( 'start' => '2026-12-01 09:00' ),
				array( 'start' => '2026-12-02 09:00' ),
				array( 'start' => '2026-12-03 09:00' ),
			)
		);
		$empty    = $this->create_event();

		update_option( 'timezone_string', 'America/New_York' );

		$this->assertSame( '2026-12-01 09:00', $this->render( Admin_Columns::COLUMN_DATE, $single ) );
		$this->assertSame( '2026-12-01 09:00 (+2 more dates)', $this->render( Admin_Columns::COLUMN_DATE, $multiple ) );
		$this->assertSame( '—', $this->render( Admin_Columns::COLUMN_DATE, $empty ) );
	}

	/**
	 * 都道府県の列。
	 */
	public function test_prefecture_column() {
		$tokyo = $this->create_event();
		update_post_meta( $tokyo, Event_Meta::PREFECTURE, 13 );
		$online = $this->create_event();
		update_post_meta( $online, Event_Meta::ONLINE, true );
		$empty = $this->create_event();

		$this->assertSame( 'Tokyo', $this->render( Admin_Columns::COLUMN_PREFECTURE, $tokyo ) );
		$this->assertSame( 'Online', $this->render( Admin_Columns::COLUMN_PREFECTURE, $online ) );
		$this->assertSame( '—', $this->render( Admin_Columns::COLUMN_PREFECTURE, $empty ) );
	}

	/**
	 * 開催日で並べ替えても、日程の無い投稿が一覧から消えない。
	 */
	public function test_sort_by_date_keeps_events_without_dates() {
		set_current_screen( 'edit-' . Post_Type::POST_TYPE );

		$late  = $this->create_event( array( array( 'start' => '2026-12-20 09:00' ) ) );
		$early = $this->create_event( array( array( 'start' => '2026-12-01 09:00' ) ) );
		$none  = $this->create_event();
		// 編集画面で開いた下書きには、開催日に近い大きさの数字で始まるメタが付く。
		update_post_meta( $none, '_edit_lock', ( strtotime( '2026-12-10 00:00 UTC' ) ) . ':1' );

		$order = function ( $direction ) {
			global $wp_the_query;
			$query        = new WP_Query();
			$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- is_main_query() を成り立たせる。
			return $query->query(
				array(
					'post_type'   => Post_Type::POST_TYPE,
					'post_status' => 'any',
					'orderby'     => Admin_Columns::ORDERBY,
					'order'       => $direction,
					'fields'      => 'ids',
				)
			);
		};

		$this->assertSame( array( $none, $early, $late ), array_map( 'intval', $order( 'ASC' ) ) );
		$this->assertSame( array( $late, $early, $none ), array_map( 'intval', $order( 'DESC' ) ) );

		set_current_screen( 'front' );
	}
}
