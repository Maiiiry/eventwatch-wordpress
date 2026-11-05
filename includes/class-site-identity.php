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
 *
 * 記録は 16 進で持つ。平文だと、複製に使う wp search-replace や移行ツールの置換で home と一緒に書き換わり、違いが消える。
 * 隠すためではない。値はサイトの公開 URL である。
 */
class Site_Identity {

	const ID_OPTION  = 'eventwatch_site_id';
	const URL_OPTION = 'eventwatch_site_url_hex';

	/**
	 * サイト ID と URL の記録を作る。あれば何もしない。キーの option も自動の読み込みから外して先に作る。
	 *
	 * @return void
	 */
	public static function ensure() {
		add_option( self::ID_OPTION, wp_generate_uuid4(), '', false );
		add_option( self::URL_OPTION, self::encode_url( self::current_url() ), '', false );

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
	 * URL が記録と違うか。記録が無ければ今の URL を記録して偽を返す。読めない記録は違うものとして止める。
	 *
	 * @return bool
	 */
	public static function has_changed() {
		self::ensure();

		return self::normalize( self::recorded_url() ) !== self::normalize( self::current_url() );
	}

	/**
	 * 記録した URL。16 進として読めなければ空文字。
	 *
	 * @return string
	 */
	public static function recorded_url() {
		self::ensure();

		$recorded = (string) get_option( self::URL_OPTION );

		// hex2bin は読めない値で警告を出す。
		return preg_match( '/^(?:[0-9a-f]{2})+$/D', $recorded ) ? (string) hex2bin( $recorded ) : '';
	}

	/**
	 * 記録に書く形。
	 *
	 * @param string $url URL。
	 * @return string
	 */
	public static function encode_url( $url ) {
		return bin2hex( $url );
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
	 * EventWatch へ送るサイトの URL。送れない値の項目は持たない。
	 *
	 * EventWatch は 1024 を超える値などを 400 で断り、投稿も送れなくなる。サイトの URL は投稿者が直せる値ではないので、
	 * 投稿を止めない。ID・パスワードとクエリを持つ値は、団体のメンバーの画面に出すものではないので送らない。
	 *
	 * @return array{siteUrl?: string, adminUrl?: string}
	 */
	public static function urls() {
		$urls = array(
			'siteUrl'  => self::current_url(),
			'adminUrl' => self::admin_url(),
		);

		// WP_SITEURL を Host ヘッダーから作るサイトでは、要求ひとつで別のホストになる。移転の確認は home しか見ない。
		if ( self::host( $urls['siteUrl'] ) !== self::host( $urls['adminUrl'] ) ) {
			unset( $urls['adminUrl'] );
		}

		return array_filter(
			$urls,
			static function ( $url ) {
				return preg_match( '~^https?://[^\s/?#@]+(?:/[^\s?#]*)?$~D', $url ) && Sync_Payload::server_length( $url ) <= Event_Meta::MAX_URL;
			}
		);
	}

	/**
	 * 管理画面の URL。送る内容のハッシュに入るので、要求の経路で値を変えない。admin_url() のスキームは is_ssl() で変わり、
	 * フィルターは管理画面を隠すプラグインの秘密のパスを返すことがあるので、option から作る。
	 *
	 * @return string
	 */
	private static function admin_url() {
		$url = trailingslashit( (string) get_option( 'siteurl' ) ) . 'wp-admin/';

		return force_ssl_admin() ? set_url_scheme( $url, 'https' ) : $url;
	}

	/**
	 * 比べるホスト。www の有無は同じサイトとして扱う。
	 *
	 * @param string $url URL。
	 * @return string
	 */
	private static function host( $url ) {
		return (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}

	/**
	 * 移転として続ける。サイト ID はそのまま。
	 *
	 * @return void
	 */
	public static function use_as_moved() {
		update_option( self::URL_OPTION, self::encode_url( self::current_url() ), false );
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
		update_option( self::URL_OPTION, self::encode_url( self::current_url() ), false );
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
