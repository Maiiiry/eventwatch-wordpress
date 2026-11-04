<?php
/**
 * 取り込みの画面の表のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Connection;
use EventWatch\Event_Meta;
use EventWatch\Import_List_Table;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Queue;
use WP_UnitTestCase;

/**
 * Import_List_Table のテスト。
 */
class Import_List_Table_Test extends WP_UnitTestCase {

	const ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * テストごとに登録し直す。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Site_Identity::use_as_moved();
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
	}

	/**
	 * 状態を戻す。
	 */
	public function tear_down() {
		Sync_Queue::forget_all();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * 表を描く。
	 *
	 * @param array $event 投稿の欄。
	 * @return string
	 */
	private function render( array $event = array() ) {
		$item = array_merge(
			array(
				'id'               => self::ID,
				'status'           => 'draft',
				'title'            => '<b>題</b> & 名',
				'zoneId'           => 'Asia/Tokyo',
				'occurrenceRanges' => array(
					array(
						'startAt' => '2030-05-01 10:00',
						'closeAt' => '',
					),
				),
			),
			$event
		);

		ob_start();
		( new Import_List_Table(
			array(
				'list'  => array( $item ),
				'total' => 1,
			)
		) )->display();

		return (string) ob_get_clean();
	}

	/**
	 * 行に JS が読む印と列があり、題を escape する。
	 */
	public function test_row() {
		$html = $this->render();

		$this->assertStringContainsString( 'data-eventwatch-event="' . self::ID . '"', $html );
		$this->assertStringContainsString( 'name="eventwatch_import[]" value="' . self::ID . '"', $html );
		$this->assertStringContainsString( 'column-wordpress', $html );
		$this->assertStringContainsString( 'data-eventwatch-import-start', $html );
		$this->assertStringContainsString( 'data-eventwatch-import-count', $html );
		$this->assertStringContainsString( '&lt;b&gt;題&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>題', $html );
		$this->assertStringContainsString( 'Upcoming', $html );
		$this->assertStringContainsString( 'Draft', $html );
	}

	/**
	 * 終わった投稿と差し戻しの理由。
	 */
	public function test_ended_and_reason() {
		$html = $this->render(
			array(
				'status'           => 'rejected',
				'rejectionReason'  => '<i>日付</i>',
				'occurrenceRanges' => array(
					array(
						'startAt' => '2020-05-01 10:00',
						'closeAt' => '',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Ended', $html );
		$this->assertStringContainsString( 'Changes requested', $html );
		$this->assertStringContainsString( 'eventwatch-sync-reason', $html );
		$this->assertStringNotContainsString( '<i>日付', $html );
	}

	/**
	 * 手元に下書きがあれば、WordPress の列にリンクを出す。
	 */
	public function test_local_draft_link() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, Sync_Queue::IMPORT_SOURCE, self::ID );

		$this->assertStringContainsString( 'post=' . $post_id, $this->render() );
	}

	/**
	 * 空の案内。ボタンは出さない。
	 */
	public function test_empty() {
		ob_start();
		( new Import_List_Table() )->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'There are no posts to import', $html );
		$this->assertStringNotContainsString( 'data-eventwatch-import-start', $html );
	}
}
