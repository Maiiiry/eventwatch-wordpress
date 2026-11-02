<?php
/**
 * 接頭辞の変更・転送・設定のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_Permalink;
use EventWatch\Post_Type;
use EventWatch\Settings;
use WP_UnitTestCase;

/**
 * Event_Permalink と Settings のテスト。
 */
class Event_Permalink_Test extends WP_UnitTestCase {

	/**
	 * 作り直しの回数を数えるフック。
	 *
	 * @var int
	 */
	private $flushes = 0;

	/**
	 * パーマリンクの形を揃える。
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Settings::register_settings();
		$this->flushes = 0;
		add_action( 'generate_rewrite_rules', array( $this, 'count_flush' ) );
	}

	/**
	 * 接頭辞を既定に戻す。
	 */
	public function tear_down() {
		remove_action( 'generate_rewrite_rules', array( $this, 'count_flush' ) );
		delete_option( Post_Type::SLUG_OPTION );
		delete_option( Event_Permalink::PREVIOUS );
		delete_option( Event_Permalink::FLUSH_FLAG );
		Post_Type::register();
		parent::tear_down();
	}

	/**
	 * 作り直しを数える。
	 */
	public function count_flush() {
		++$this->flushes;
	}

	/**
	 * 公開のイベントを作る。
	 *
	 * @param string $slug   スラッグ。
	 * @param string $status 状態。
	 * @return int
	 */
	private function event( $slug, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => $status,
				'post_name'   => $slug,
				'post_title'  => $slug,
				// 過去の日付の予約は、本体が公開に変える。
				'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '',
			)
		);
	}

	/**
	 * 既定は event。設定があればそれで登録する。
	 */
	public function test_rewrite_slug_comes_from_the_option() {
		$this->assertSame( 'event', Post_Type::rewrite_slug() );

		update_option( Post_Type::SLUG_OPTION, 'events' );
		Post_Type::register();

		$this->assertSame( 'events', get_post_type_object( Post_Type::POST_TYPE )->rewrite['slug'] );
		$this->assertStringContainsString( '/events/sample/', get_permalink( $this->event( 'sample' ) ) );
	}

	/**
	 * 保存では作り直さず、次の init で 1 回だけ作り直す。
	 */
	public function test_flush_runs_once_on_the_next_load() {
		update_option( Post_Type::SLUG_OPTION, 'events' );

		$this->assertSame( 0, $this->flushes, '保存の要求の中では作り直さない' );
		$this->assertSame( '1', (string) get_option( Event_Permalink::FLUSH_FLAG ) );

		Event_Permalink::flush_if_flagged();
		$this->assertSame( 1, $this->flushes );
		$this->assertFalse( get_option( Event_Permalink::FLUSH_FLAG ) );

		Event_Permalink::flush_if_flagged();
		$this->assertSame( 1, $this->flushes, '2 度目の読み込みでは走らない' );
	}

	/**
	 * 同じ接頭辞を保存しても印を立てない。既定を入れ直したときも同じ。
	 */
	public function test_saving_the_same_slug_does_not_flag() {
		update_option( Post_Type::SLUG_OPTION, 'event' );
		$this->assertFalse( get_option( Event_Permalink::FLUSH_FLAG ) );

		update_option( Post_Type::SLUG_OPTION, 'events' );
		delete_option( Event_Permalink::FLUSH_FLAG );
		update_option( Post_Type::SLUG_OPTION, 'events' );
		$this->assertFalse( get_option( Event_Permalink::FLUSH_FLAG ) );
	}

	/**
	 * 誤った値は保存せず、元の値を保つ。空は既定に戻す。
	 *
	 * @dataProvider invalid_slugs
	 *
	 * @param string $slug 入力。
	 */
	public function test_invalid_slug_keeps_the_old_value( $slug ) {
		update_option( Post_Type::SLUG_OPTION, 'events' );
		update_option( Post_Type::SLUG_OPTION, $slug );

		$this->assertSame( 'events', get_option( Post_Type::SLUG_OPTION ) );
	}

	/**
	 * 断る値。
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_slugs() {
		return array(
			'大文字'     => array( 'Events' ),
			'斜線'      => array( 'a/b' ),
			'日本語'     => array( 'イベント' ),
			'空白'      => array( 'my events' ),
			'先頭のハイフン' => array( '-events' ),
			'本体のパス'   => array( 'wp-admin' ),
			'本体の語'    => array( 'feed' ),
		);
	}

	/**
	 * 実在の固定ページ・パーマリンクの固定の語・数字だけの値は、URL を奪うので断る。
	 */
	public function test_slug_that_takes_over_existing_urls_is_rejected() {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'about',
			)
		);
		update_option( Post_Type::SLUG_OPTION, 'events' );

		update_option( Post_Type::SLUG_OPTION, 'about' );
		$this->assertSame( 'events', get_option( Post_Type::SLUG_OPTION ), '固定ページ' );

		update_option( Post_Type::SLUG_OPTION, '2026' );
		$this->assertSame( 'events', get_option( Post_Type::SLUG_OPTION ), '年' );

		$this->set_permalink_structure( '/blog/%postname%/' );
		update_option( Post_Type::SLUG_OPTION, 'blog' );
		$this->assertSame( 'events', get_option( Post_Type::SLUG_OPTION ), 'パーマリンクの固定の語' );

		update_option( Post_Type::SLUG_OPTION, 'shows' );
		$this->assertSame( 'shows', get_option( Post_Type::SLUG_OPTION ), '当たらない語は通す' );
	}

	/**
	 * 記号だけの URL を、無関係なイベントへ転送しない。
	 */
	public function test_symbol_only_url_does_not_redirect() {
		$this->event( 'sample' );
		update_option( Post_Type::SLUG_OPTION, 'events' );

		$this->assertNull( Event_Permalink::redirect_target( '/event/%21/' ) );
		$this->assertNull( Event_Permalink::redirect_target( '/event/---/' ) );
	}

	/**
	 * Index.php を含むパーマリンクでも転送する。
	 */
	public function test_redirect_with_index_php_permalinks() {
		$id = $this->event( 'sample' );
		$this->set_permalink_structure( '/index.php/%postname%/' );
		update_option( Post_Type::SLUG_OPTION, 'events' );
		Post_Type::register();

		$this->assertSame( get_permalink( $id ), Event_Permalink::redirect_target( '/index.php/event/sample/' ) );
	}

	/**
	 * 空を保存すると既定に戻り、旧い接頭辞を転送の対象に残す。
	 */
	public function test_empty_slug_returns_to_the_default() {
		update_option( Post_Type::SLUG_OPTION, 'events' );
		update_option( Post_Type::SLUG_OPTION, '' );

		$this->assertSame( 'event', Post_Type::rewrite_slug() );
		$this->assertSame( array( 'events' ), get_option( Event_Permalink::PREVIOUS ) );
	}

	/**
	 * 旧い URL から新しい URL へ。クエリを保つ。2 回変えても最初の接頭辞から届く。
	 */
	public function test_old_urls_redirect_to_the_new_url() {
		$id = $this->event( 'sample' );

		update_option( Post_Type::SLUG_OPTION, 'events' );
		update_option( Post_Type::SLUG_OPTION, 'shows' );
		Post_Type::register();

		$new = get_permalink( $id );
		$this->assertStringContainsString( '/shows/sample/', $new );
		$this->assertSame( $new, Event_Permalink::redirect_target( '/event/sample/' ) );
		$this->assertSame( $new, Event_Permalink::redirect_target( '/events/sample' ) );
		$this->assertSame( $new . '?a=1&b=2', Event_Permalink::redirect_target( '/event/sample/?a=1&b=2' ) );
	}

	/**
	 * 元の接頭辞へ戻したら、いまの接頭辞は対象から外れ、経由した接頭辞だけが残る。
	 */
	public function test_returning_to_an_old_slug_removes_it_from_the_list() {
		update_option( Post_Type::SLUG_OPTION, 'events' );
		update_option( Post_Type::SLUG_OPTION, 'event' );

		$this->assertSame( array( 'events' ), get_option( Event_Permalink::PREVIOUS ) );
	}

	/**
	 * 公開でないイベントへは転送しない。
	 *
	 * @dataProvider hidden_statuses
	 *
	 * @param string $status 状態。
	 */
	public function test_hidden_events_do_not_redirect( $status ) {
		$this->event( 'hidden', $status );
		update_option( Post_Type::SLUG_OPTION, 'events' );

		$this->assertNull( Event_Permalink::redirect_target( '/event/hidden/' ) );
	}

	/**
	 * 公開でない状態。
	 *
	 * @return array<string, array{string}>
	 */
	public function hidden_statuses() {
		return array(
			'下書き' => array( 'draft' ),
			'非公開' => array( 'private' ),
			'予約'  => array( 'future' ),
			'ゴミ箱' => array( 'trash' ),
		);
	}

	/**
	 * 関係のない URL は転送しない。
	 */
	public function test_unrelated_urls_do_not_redirect() {
		$this->event( 'sample' );
		update_option( Post_Type::SLUG_OPTION, 'events' );

		$this->assertNull( Event_Permalink::redirect_target( '/other/sample/' ), '使ったことのない接頭辞' );
		$this->assertNull( Event_Permalink::redirect_target( '/event/missing/' ), '無いイベント' );
		$this->assertNull( Event_Permalink::redirect_target( '/event/sample/extra/' ), '深いパス' );
		$this->assertNull( Event_Permalink::redirect_target( '/event/' ), '接頭辞だけ' );
	}

	/**
	 * 接頭辞を変えていなければ、event の URL も転送の対象にしない。
	 */
	public function test_no_redirect_without_a_change() {
		$this->event( 'sample' );

		$this->assertNull( Event_Permalink::redirect_target( '/event/sample/' ) );
	}

	/**
	 * サブディレクトリのサイトでは、その下のパスだけを見る。
	 */
	public function test_subdirectory_site() {
		$this->event( 'sample' );
		update_option( Post_Type::SLUG_OPTION, 'events' );
		Post_Type::register();

		$home = static function () {
			return 'http://example.org/blog/';
		};
		add_filter( 'home_url', $home );
		$target_inside  = Event_Permalink::redirect_target( '/blog/event/sample/' );
		$target_outside = Event_Permalink::redirect_target( '/event/sample/' );
		remove_filter( 'home_url', $home );

		$this->assertNotNull( $target_inside );
		$this->assertNull( $target_outside );
	}

	/**
	 * 一覧のページは公開の固定ページだけ。
	 */
	public function test_list_page_accepts_only_published_pages() {
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$post  = self::factory()->post->create();

		$this->assertSame( $page, Settings::sanitize_list_page( (string) $page ) );
		$this->assertSame( 0, Settings::sanitize_list_page( $draft ) );
		$this->assertSame( 0, Settings::sanitize_list_page( $post ) );
		$this->assertSame( 0, Settings::sanitize_list_page( 'x' ) );
	}

	/**
	 * Google Maps のキーは保存・消去でき、形の違う値は元の値を保つ。自動の読み込みに載らない。
	 */
	public function test_maps_key() {
		update_option( Settings::MAPS_KEY, ' AIza_abc-123 ' );
		$this->assertSame( 'AIza_abc-123', get_option( Settings::MAPS_KEY ) );

		update_option( Settings::MAPS_KEY, 'a&b="x"' );
		$this->assertSame( 'AIza_abc-123', get_option( Settings::MAPS_KEY ) );

		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Settings::MAPS_KEY, wp_load_alloptions() );

		update_option( Settings::MAPS_KEY, '' );
		$this->assertSame( '', get_option( Settings::MAPS_KEY ) );
	}
}
