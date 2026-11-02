<?php
/**
 * プラグインの設定画面（設定 › EventWatch）。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存は Settings API に任せる。権限と nonce は options.php が確かめる。
 */
class Settings {

	const PAGE           = 'eventwatch';
	const GROUP          = 'eventwatch';
	const SECTION_DETAIL = 'eventwatch_detail';
	const LIST_PAGE      = 'eventwatch_list_page';
	const MAPS_KEY       = 'eventwatch_google_maps_key';

	/**
	 * Google のキーの取り方の案内。
	 */
	const MAPS_KEY_HELP_URL = 'https://developers.google.com/maps/documentation/embed/get-api-key';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		// 管理画面の外の更新（WP-CLI など）にも sanitize を効かせるので、init で登録する。
		add_action( 'init', array( self::class, 'register_settings' ) );
		add_action( 'admin_init', array( self::class, 'add_fields' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_filter( 'wp_default_autoload_value', array( self::class, 'exclude_key_from_autoload' ), 10, 2 );
	}

	/**
	 * 設定を登録する。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Post_Type::SLUG_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( Event_Permalink::class, 'sanitize_slug' ),
			)
		);
		register_setting(
			self::GROUP,
			self::LIST_PAGE,
			array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => array( self::class, 'sanitize_list_page' ),
			)
		);
		register_setting(
			self::GROUP,
			self::MAPS_KEY,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_maps_key' ),
			)
		);
	}

	/**
	 * Google Maps のキーを毎回の読み込みに載せない。使うのは詳細のページだけ。
	 *
	 * @param bool|null $autoload 自動で読むか。
	 * @param string    $option   名前。
	 * @return bool|null
	 */
	public static function exclude_key_from_autoload( $autoload, $option ) {
		return self::MAPS_KEY === $option ? false : $autoload;
	}

	/**
	 * 一覧のページ。公開の固定ページだけを受ける。
	 *
	 * @param mixed $value 入力。
	 * @return int
	 */
	public static function sanitize_list_page( $value ) {
		$page = get_post( absint( $value ) );

		return $page && 'page' === $page->post_type && 'publish' === $page->post_status ? (int) $page->ID : 0;
	}

	/**
	 * Google Maps のキー。URL に載せるので、形の違う値を受けない。誤りのときは元の値を保つ。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_maps_key( $value ) {
		$key = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $key || preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
			return $key;
		}

		// 管理画面の外（WP-CLI など）にはこの関数が無い。
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::MAPS_KEY, 'eventwatch_invalid_maps_key', __( 'The Google Maps API key can contain only letters, numbers, hyphens, and underscores.', 'eventwatch' ) );
		}

		return (string) get_option( self::MAPS_KEY, '' );
	}

	/**
	 * 画面をメニューに足す。
	 *
	 * @return void
	 */
	public static function add_page() {
		add_options_page( 'EventWatch', 'EventWatch', 'manage_options', self::PAGE, array( self::class, 'render_page' ) );
	}

	/**
	 * 欄を足す。
	 *
	 * @return void
	 */
	public static function add_fields() {
		add_settings_section( self::SECTION_DETAIL, __( 'Event pages', 'eventwatch' ), '__return_false', self::PAGE );

		add_settings_field(
			Post_Type::SLUG_OPTION,
			__( 'URL prefix', 'eventwatch' ),
			array( self::class, 'render_slug_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => Post_Type::SLUG_OPTION )
		);
		add_settings_field(
			self::LIST_PAGE,
			__( 'Event list page', 'eventwatch' ),
			array( self::class, 'render_list_page_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => self::LIST_PAGE )
		);
		add_settings_field(
			self::MAPS_KEY,
			__( 'Google Maps API key (optional)', 'eventwatch' ),
			array( self::class, 'render_maps_key_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => self::MAPS_KEY )
		);
	}

	/**
	 * 画面を描く。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * 接頭辞の欄。
	 *
	 * @return void
	 */
	public static function render_slug_field() {
		printf(
			'<code>%1$s</code><input type="text" class="regular-text code" id="%2$s" name="%2$s" value="%3$s" placeholder="%4$s" spellcheck="false" aria-describedby="%2$s-description"><code>/my-event/</code>',
			esc_html( home_url( '/' ) ),
			esc_attr( Post_Type::SLUG_OPTION ),
			esc_attr( (string) get_option( Post_Type::SLUG_OPTION, '' ) ),
			esc_attr( Post_Type::REWRITE_SLUG )
		);
		printf(
			'<p class="description" id="%1$s-description">%2$s</p>',
			esc_attr( Post_Type::SLUG_OPTION ),
			esc_html__( 'Lowercase letters, numbers, and hyphens. When you change it, the old URLs redirect to the new ones.', 'eventwatch' )
		);
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'This has no effect while your permalinks use the Plain setting.', 'eventwatch' )
			);
		}
	}

	/**
	 * 一覧のページの欄。
	 *
	 * @return void
	 */
	public static function render_list_page_field() {
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::LIST_PAGE ),
				'id'                => esc_attr( self::LIST_PAGE ),
				'selected'          => absint( get_option( self::LIST_PAGE, 0 ) ),
				'show_option_none'  => esc_html__( '— Not set —', 'eventwatch' ),
				'option_none_value' => '0',
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The “Back to events” link on event pages goes here. The link is hidden until you choose a page.', 'eventwatch' )
		);
	}

	/**
	 * Google Maps のキーの欄。
	 *
	 * @return void
	 */
	public static function render_maps_key_field() {
		printf(
			'<input type="password" class="regular-text code" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" autocomplete="off" spellcheck="false">',
			esc_attr( self::MAPS_KEY ),
			esc_attr( (string) get_option( self::MAPS_KEY, '' ) ),
			esc_attr__( 'Not set', 'eventwatch' )
		);

		$lines = array(
			__( 'When set, a venue map is embedded on event detail pages. When empty, only map links are shown.', 'eventwatch' ),
			__( 'Embedding a map makes visitors’ browsers load the map from Google. The key appears in the page HTML, so restrict it to your site’s domain with an HTTP referrer restriction.', 'eventwatch' ),
			__( 'Create the key in Google Cloud (with the Maps Embed API enabled). As the site owner, you’re responsible for reviewing Google’s terms and pricing. This key is not sent to EventWatch.', 'eventwatch' ),
		);
		foreach ( $lines as $line ) {
			printf( '<p class="description">%s</p>', esc_html( $line ) );
		}

		printf(
			'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( self::MAPS_KEY_HELP_URL ),
			esc_html__( 'How to get an API key (Google)', 'eventwatch' )
		);
	}
}
