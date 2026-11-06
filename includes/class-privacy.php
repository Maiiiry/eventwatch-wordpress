<?php
/**
 * サイトのプライバシーポリシーに載せる文案。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定 > プライバシー > ポリシーガイドに、このプラグインの分の文案を出す。
 *
 * 文案は readme の External services と Maps の節と同じことを書く。送るものを変えたら両方を直す。
 */
class Privacy {

	/**
	 * ポリシーガイドに出す節の名前。
	 *
	 * @var string
	 */
	const SECTION = 'EventWatch Events';

	/**
	 * Google のプライバシーポリシー。
	 *
	 * @var string
	 */
	const GOOGLE_PRIVACY_URL = 'https://policies.google.com/privacy';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		// 本体は admin_init の中か後でしか文案を受け付けない。
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/**
	 * 文案を本体に渡す。
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		wp_add_privacy_policy_content( self::SECTION, self::policy_text() );
	}

	/**
	 * 文案の HTML。privacy-policy-tutorial の段落はオーナー向けの説明で、「コピー」では写らない。
	 *
	 * @return string
	 */
	public static function policy_text() {
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'EventWatch Events does not set cookies and does not collect information about visitors. It connects to outside services only after an administrator sets them up. Remove the parts below that do not match your settings.', 'eventwatch' ) . '</p>',
			'<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'eventwatch' ) . ' </strong>',
			'<p>' . sprintf(
				/* translators: %s: URL of the EventWatch privacy policy. */
				esc_html__( 'This site lists its events on EventWatch. When we publish an event, our server sends it to EventWatch: its title, content, dates, venue, and other details, its featured image, and the addresses of this site and its admin area. No information about visitors to this site is sent. EventWatch\'s privacy policy: %s', 'eventwatch' ),
				esc_url( Connection_Admin::web_url( '/privacy' ) )
			) . '</p>',
			'<p>' . sprintf(
				/* translators: %s: URL of the Google privacy policy. */
				esc_html__( 'Event pages show a Google map of the venue. When you open an event page, your browser connects to Google, and Google may collect information such as your IP address. Google\'s privacy policy: %s', 'eventwatch' ),
				esc_url( self::GOOGLE_PRIVACY_URL )
			) . '</p>',
			'<p>' . esc_html__( 'Event pages link to EventWatch, maps, calendars, and social media. Your browser connects to these services only when you follow a link.', 'eventwatch' ) . '</p>',
		);

		return implode( "\n", $paragraphs );
	}
}
