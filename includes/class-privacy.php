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
	 * EventWatch と Google の段落は、いまの設定で接続するときだけ入れる。admin_init のたびに作り直す。
	 *
	 * @return string
	 */
	public static function policy_text() {
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'EventWatch Events does not set cookies and does not collect information about visitors. It connects to outside services only after an administrator sets them up. The suggested text below follows your current settings. If you change the EventWatch or Google Maps settings, review your policy again.', 'eventwatch' ) . '</p>',
			'<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'eventwatch' ) . ' </strong>',
		);

		// 送るのはキーを設定したときだけ（readme の External services）。
		if ( '' !== Connection::api_key() ) {
			$paragraphs[] = '<p>' . sprintf(
				/* translators: %s: URL of the EventWatch privacy policy. */
				esc_html__( 'This site lists its events on EventWatch. When we publish an event, our server sends it to EventWatch: its title, content, dates, venue, and other details, its featured image, and the addresses of this site and its admin area. No information about visitors to this site is sent. EventWatch\'s privacy policy: %s', 'eventwatch' ),
				self::link( Connection_Admin::web_url( '/privacy' ) )
			) . '</p>';
		}

		// 地図を埋め込むのは Google Maps のキーを設定したときだけで、既定はリンクだけ（readme の Maps）。
		if ( '' !== (string) get_option( Settings::MAPS_KEY, '' ) ) {
			$paragraphs[] = '<p>' . sprintf(
				/* translators: %s: URL of the Google privacy policy. */
				esc_html__( 'Event pages show a Google map of the venue. When you open an event page, your browser connects to Google, and Google may collect information such as your IP address. Google\'s privacy policy: %s', 'eventwatch' ),
				self::link( self::GOOGLE_PRIVACY_URL )
			) . '</p>';
		}

		$paragraphs[] = '<p>' . esc_html__( 'Event pages link to maps, calendars, and social media, and events listed on EventWatch link to EventWatch. Your browser connects to these services only when you follow a link.', 'eventwatch' ) . '</p>';

		return implode( "\n", $paragraphs );
	}

	/**
	 * URL をそのまま文字にしたリンク。オーナーがポリシーへ写した後も開ける形にする。
	 *
	 * @param string $url URL。
	 * @return string
	 */
	private static function link( $url ) {
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $url ) );
	}
}
