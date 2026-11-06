<?php
/**
 * プライバシーポリシーの文案のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Privacy;
use WP_Privacy_Policy_Content;
use WP_UnitTestCase;

/**
 * Privacy のテスト。
 */
class Privacy_Test extends WP_UnitTestCase {

	/**
	 * 管理画面にいる状態にする。
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
	}

	/**
	 * 状態を戻す。
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * ポリシーガイドに出る、このプラグインの文案。
	 *
	 * @return string[]
	 */
	private function our_texts() {
		$texts = array();
		foreach ( WP_Privacy_Policy_Content::get_suggested_policy_text() as $item ) {
			if ( Privacy::SECTION === $item['plugin_name'] ) {
				$texts[] = $item['policy_text'];
			}
		}
		return $texts;
	}

	/**
	 * admin_init の後に、文案が 1 件だけ出る。
	 */
	public function test_adds_one_section_on_admin_init() {
		$this->assertSame( 10, has_action( 'admin_init', array( Privacy::class, 'add_policy_content' ) ) );

		// 本体の admin_init はヘッダーを送るので、テストでは自分のフックだけを流す。
		remove_all_actions( 'admin_init' );
		Privacy::boot();
		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 本体のフックを起こす
		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 2 回目でも節が増えないことを見る

		$texts = $this->our_texts();
		$this->assertCount( 1, $texts );
		$this->assertStringContainsString( 'https://eventwatch.app/en/privacy', $texts[0] );
		$this->assertStringContainsString( Privacy::GOOGLE_PRIVACY_URL, $texts[0] );
		$this->assertStringContainsString( 'No information about visitors to this site is sent.', $texts[0] );
	}

	/**
	 * オーナー向けの説明は「コピー」で写らない段落に入り、提案の文はそれ以外に入る。
	 */
	public function test_tutorial_is_marked_and_suggested_text_is_not() {
		$html = Privacy::policy_text();

		$this->assertMatchesRegularExpression( '/<p class="privacy-policy-tutorial">[^<]*does not set cookies/', $html );
		$this->assertMatchesRegularExpression( '/<p>[^<]*our server sends it to EventWatch/', $html );
		$this->assertMatchesRegularExpression( '/<p>[^<]*your browser connects to Google/', $html );
	}

	/**
	 * ja の管理画面では、EventWatch のポリシーは ja のページを指す。
	 */
	public function test_eventwatch_policy_url_follows_locale() {
		$switch = static function () {
			return 'ja';
		};
		add_filter( 'determine_locale', $switch );
		$html = Privacy::policy_text();
		remove_filter( 'determine_locale', $switch );

		$this->assertStringContainsString( 'https://eventwatch.app/privacy', $html );
		$this->assertStringNotContainsString( 'https://eventwatch.app/en/privacy', $html );
	}
}
