<?php
/**
 * サイトの識別と、複製の検知。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記録した URL と今の URL が違えば、書き込みを止める。本番を複製したステージングから本番の投稿を書き換えないため。
 */
class Site_Identity {

	const ID_OPTION  = 'eventwatch_site_id';
	const URL_OPTION = 'eventwatch_site_url';

	/**
	 * サイト ID と URL の記録を作る。あれば何もしない。キーの option も自動の読み込みから外して先に作る。
	 *
	 * @return void
	 */
	public static function ensure() {
		add_option( self::ID_OPTION, wp_generate_uuid4(), '', false );
		add_option( self::URL_OPTION, self::current_url(), '', false );

		// register_setting の既定値があると、add_option は行があっても書き込みまで進む。
		if ( false === get_option( Connection::KEY_OPTION, false ) ) {
			add_option( Connection::KEY_OPTION, '', '', false );
		}
	}

	/**
	 * サイト ID。
	 *
	 * @return string
	 */
	public static function id() {
		self::ensure();

		return (string) get_option( self::ID_OPTION );
	}

	/**
	 * URL が記録と違うか。記録が無ければ今の URL を記録して偽を返す。
	 *
	 * @return bool
	 */
	public static function has_changed() {
		self::ensure();

		return self::normalize( (string) get_option( self::URL_OPTION ) ) !== self::normalize( self::current_url() );
	}

	/**
	 * 記録した URL。
	 *
	 * @return string
	 */
	public static function recorded_url() {
		self::ensure();

		return (string) get_option( self::URL_OPTION );
	}

	/**
	 * 今の URL。多言語プラグインは home_url() を言語で変えるので、option を読む。WP_HOME は option_home のフィルターで効く。
	 *
	 * @return string
	 */
	public static function current_url() {
		return (string) get_option( 'home' );
	}

	/**
	 * 移転として続ける。サイト ID はそのまま。
	 *
	 * @return void
	 */
	public static function use_as_moved() {
		update_option( self::URL_OPTION, self::current_url(), false );
		// パーマリンクが変わるので、公式ページの URL を送り直す。止めていた送信もここで再開する。
		Sync_Queue::request_rescan();
		Sync_Queue::resume();
	}

	/**
	 * 複製として、別のサイトになる。貼り直すまで本番の団体へ送らないよう、保存したキーも消す。
	 *
	 * @return void
	 */
	public static function use_as_copy() {
		update_option( self::ID_OPTION, wp_generate_uuid4(), false );
		update_option( self::URL_OPTION, self::current_url(), false );
		delete_option( Connection::KEY_OPTION );
		add_option( Connection::KEY_OPTION, '', '', false );
		Connection::forget();
		delete_option( Sync_Status::LAST_SYNC_OPTION );
		Sync_Queue::forget_all();
	}

	/**
	 * 比べる形。http と https の切り替え・大文字小文字・末尾の / では止めない。
	 *
	 * @param string $url URL。
	 * @return string
	 */
	private static function normalize( $url ) {
		return strtolower( untrailingslashit( (string) preg_replace( '#^https?://#i', '', $url ) ) );
	}
}
