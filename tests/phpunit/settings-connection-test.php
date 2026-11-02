<?php
/**
 * 設定画面の接続の行・操作・通知と、サイトの識別のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Api_Client;
use EventWatch\Connection;
use EventWatch\Connection_Admin;
use EventWatch\Event_Meta;
use EventWatch\Plugin;
use EventWatch\Post_Type;
use EventWatch\Settings;
use EventWatch\Site_Identity;
use EventWatch\Sync_Status;
use RuntimeException;
use WP_UnitTestCase;
use WPDieException;

/**
 * Settings・Connection_Admin・Site_Identity のテスト。
 */
class Settings_Connection_Test extends WP_UnitTestCase {

	const KEY = 'ewk_a1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Bxyz1';

	/**
	 * EventWatch への要求。
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * 管理画面の関数を読み込み、要求を記録する。
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		$this->sent = array();
		delete_option( Connection::KEY_OPTION );
		Connection::forget();
		Site_Identity::use_as_moved();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->sent[] = array(
					'args' => $args,
					'url'  => $url,
				);
				return new \WP_Error( 'http_request_failed', 'EventWatch is down' );
			},
			10,
			3
		);
	}

	/**
	 * 画面とユーザーを戻す。
	 */
	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 次のテストへ画面を持ち越さない。
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 出力を受ける。
	 *
	 * @param callable $callback 描く処理。
	 * @return string
	 */
	private function output_of( callable $callback ) {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * 管理者になる。
	 *
	 * @return int
	 */
	private function log_in_as_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * リダイレクトを例外にして、操作の最後の exit を避ける。
	 *
	 * @return void
	 */
	private function stop_at_redirect() {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- テストの中で読むだけ。
			}
		);
	}

	/**
	 * 操作を nonce つきで呼ぶ。戻り先の URL を返す。
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 * @return string
	 */
	private function run_action( $action, callable $handler ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$this->stop_at_redirect();

		try {
			$handler();
		} catch ( RuntimeException $redirect ) {
			return substr( $redirect->getMessage(), strlen( 'redirect:' ) );
		}

		$this->fail( 'The action did not redirect.' );
	}

	/**
	 * 操作と受ける処理の表。
	 *
	 * @return array[]
	 */
	public function actions() {
		return array(
			'check'  => array( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) ),
			'remove' => array( Connection_Admin::ACTION_REMOVE_KEY, array( Connection_Admin::class, 'handle_remove_key' ) ),
			'moved'  => array( Connection_Admin::ACTION_MOVED, array( Connection_Admin::class, 'handle_moved' ) ),
			'copy'   => array( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) ),
			'hide'   => array( Connection_Admin::ACTION_HIDE, array( Connection_Admin::class, 'handle_hide_notice' ) ),
		);
	}

	/**
	 * Nonce が無いと何も変えない。
	 *
	 * @dataProvider actions
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 */
	public function test_actions_need_a_nonce( $action, $handler ) {
		$user_id = $this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();

		try {
			$handler();
			$this->fail( 'wp_die was not called: ' . $action );
		} catch ( WPDieException $e ) {
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
			$this->assertSame( $id, Site_Identity::id() );
			$this->assertSame( array(), $this->sent );
			$this->assertSame( '', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );
		}
	}

	/**
	 * 管理者以外は nonce があっても何も変えない。
	 *
	 * @dataProvider actions
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 */
	public function test_actions_need_manage_options( $action, $handler ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();

		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		try {
			$handler();
			$this->fail( 'wp_die was not called: ' . $action );
		} catch ( WPDieException $e ) {
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
			$this->assertSame( $id, Site_Identity::id() );
			$this->assertSame( array(), $this->sent );
			$this->assertSame( '', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );
		}
	}

	/**
	 * 確かめると EventWatch を呼び、設定画面へ戻る。
	 */
	public function test_check_action() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$location = $this->run_action( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( Connection::UNREACHABLE, Connection::state()['status'] );
		$this->assertStringContainsString( 'page=eventwatch', $location );
		$this->assertStringContainsString( 'settings-updated=true', $location );
	}

	/**
	 * キーを削除すると、自動の読み込みに載らないまま空になる。
	 */
	public function test_remove_key_action() {
		global $wpdb;

		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$this->run_action( Connection_Admin::ACTION_REMOVE_KEY, array( Connection_Admin::class, 'handle_remove_key' ) );

		$this->assertSame( '', Connection::api_key() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Connection::KEY_OPTION ) ), array( 'off', 'no', 'auto-off' ) );
	}

	/**
	 * キーを保存できる。形の違う値と空は元の値を保つ。
	 */
	public function test_sanitize_api_key() {
		update_option( Connection::KEY_OPTION, ' ' . self::KEY . ' ' );
		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );

		foreach ( array( 'ewk_short', 'abc', 'ewk_' . str_repeat( 'a', 44 ), 'ewk_' . str_repeat( '-', 43 ), self::KEY . "\nx", '"><script>' ) as $bad ) {
			update_option( Connection::KEY_OPTION, $bad );
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ), $bad );
		}

		update_option( Connection::KEY_OPTION, '' );
		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );

		update_option( Connection::KEY_OPTION, array( 'x' ) );
		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
	}

	/**
	 * 固定のキーがあれば、入力を受けない。
	 */
	public function test_sanitize_ignores_input_when_fixed() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return 'ewk_fixed';
			}
		);

		update_option( Connection::KEY_OPTION, self::KEY );

		$this->assertSame( '', (string) get_option( Connection::KEY_OPTION ) );
	}

	/**
	 * 保存した欄の HTML に平文が無い。替える欄は空で、name を持つ。
	 */
	public function test_saved_key_is_never_rendered() {
		update_option( Connection::KEY_OPTION, self::KEY );

		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( substr( self::KEY, 12 ), $html );
		$this->assertStringContainsString( Connection::masked_key(), $html );
		$this->assertMatchesRegularExpression( '/<input type="password"[^>]*name="eventwatch_api_key"/', $html );
		$this->assertStringNotContainsString( 'value="ewk_a1Ba1Ba1Ba1', str_replace( 'value="' . Connection::masked_key() . '"', '', $html ) );
		$this->assertStringContainsString( Connection_Admin::ACTION_REMOVE_KEY, $html );
	}

	/**
	 * 固定のキーは読み取り専用で、欄と削除を出さず、平文も出さない。
	 */
	public function test_fixed_key_field_is_read_only() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return self::KEY;
			}
		);

		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( 'name="eventwatch_api_key"', $html );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_REMOVE_KEY, $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( Connection_Admin::ACTION_CHECK, $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
	}

	/**
	 * キーが無いときは、アカウントとキーが要ることと発行の画面へのリンクを出す。
	 */
	public function test_empty_field_explains_what_is_needed() {
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringContainsString( 'EventWatch account', $html );
		$this->assertStringContainsString( '/organizer/api-keys', $html );
		$this->assertStringContainsString( 'name="eventwatch_api_key"', $html );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_CHECK, $html );
	}

	/**
	 * 失効では原因と直し方を出す。団体名は escape する。
	 */
	public function test_status_messages() {
		update_option( Connection::KEY_OPTION, self::KEY );
		$fingerprint = wp_hash( self::KEY );
		$save        = static function ( $status, $name = '' ) use ( $fingerprint ) {
			update_option(
				Connection::STATE_OPTION,
				array(
					'status'       => $status,
					'organization' => $name,
					'checked_at'   => 1,
					'key'          => $fingerprint,
				)
			);
		};

		$save( Connection::CONNECTED, '<b>A & B</b>' );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringContainsString( '&lt;b&gt;A &amp; B&lt;/b&gt;', $html );

		$save( Connection::UNAUTHORIZED );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'revoked', $html );
		$this->assertStringContainsString( 'owner', $html );
		$this->assertStringContainsString( '/organizer/api-keys', $html );

		$save( Connection::SUSPENDED );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'don’t need to change it', $html );
		$this->assertStringContainsString( '/contact', $html );
		$this->assertStringNotContainsString( '/organizer/api-keys', $html );
	}

	/**
	 * ja は日本語の Web、それ以外は /en/ の下へ。
	 */
	public function test_web_url_follows_the_locale() {
		$this->assertSame( 'https://eventwatch.app/en/contact', Connection_Admin::web_url( '/contact' ) );

		add_filter(
			'determine_locale',
			static function () {
				return 'ja';
			}
		);
		$this->assertSame( 'https://eventwatch.app/contact', Connection_Admin::web_url( '/contact' ) );
	}

	/**
	 * キーと識別の option が自動の読み込みに載らない。
	 */
	public function test_options_are_not_autoloaded() {
		global $wpdb;

		delete_option( Connection::KEY_OPTION );
		delete_option( Site_Identity::ID_OPTION );
		delete_option( Site_Identity::URL_OPTION );

		// 有効化を通らずに保存する経路でも載らない。
		update_option( Connection::KEY_OPTION, self::KEY );
		Site_Identity::ensure();
		Connection::check();

		foreach ( array( Connection::KEY_OPTION, Connection::STATE_OPTION, Site_Identity::ID_OPTION, Site_Identity::URL_OPTION ) as $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$this->assertContains( $autoload, array( 'off', 'no', 'auto-off' ), $option );
		}

		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Connection::KEY_OPTION, wp_load_alloptions() );
	}

	/**
	 * 有効化でサイト ID と URL とキーの行ができる。2 回目で変わらない。
	 */
	public function test_activation_creates_the_identity() {
		delete_option( Site_Identity::ID_OPTION );
		delete_option( Site_Identity::URL_OPTION );

		Plugin::activate();

		$id = get_option( Site_Identity::ID_OPTION );
		$this->assertTrue( wp_is_uuid( $id, 4 ) );
		$this->assertSame( get_option( 'home' ), get_option( Site_Identity::URL_OPTION ) );

		Plugin::activate();
		$this->assertSame( $id, get_option( Site_Identity::ID_OPTION ) );
	}

	/**
	 * URL が変わると書き込みが HTTP を出さない。読むだけなら通る。確かめるまで止まったまま。
	 */
	public function test_changed_url_stops_writes_until_confirmed() {
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Site_Identity::URL_OPTION, 'https://production.example.com' );

		$this->assertTrue( Site_Identity::has_changed() );

		foreach ( array( 'POST', 'PUT', 'DELETE' ) as $method ) {
			$result = ( new Api_Client() )->request( $method, '/api/v1.0/org-api/x' );
			$this->assertSame( Api_Client::SITE_CHANGED, $result->get_error_code() );
			$this->assertFalse( Api_Client::is_temporary( $result ) );
		}
		$this->assertSame( array(), $this->sent );

		( new Api_Client() )->request( 'GET', '/api/v1.0/org-api/organization' );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * 移転を選ぶと、同じサイト ID で続く。
	 */
	public function test_moved_keeps_the_id_and_key() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();
		update_option( Site_Identity::URL_OPTION, 'https://old.example.com' );

		$this->run_action( Connection_Admin::ACTION_MOVED, array( Connection_Admin::class, 'handle_moved' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertSame( $id, Site_Identity::id() );
		$this->assertSame( self::KEY, Connection::api_key() );
		$this->assertSame( Site_Identity::current_url(), Site_Identity::recorded_url() );
	}

	/**
	 * 複製を選ぶと、新しいサイト ID になり、保存したキーと記録が消える。
	 */
	public function test_copy_gets_a_new_id_and_loses_the_key() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Sync_Status::LAST_SYNC_OPTION, 123 );
		Connection::check();
		$id = Site_Identity::id();
		update_option( Site_Identity::URL_OPTION, 'https://production.example.com' );

		$this->run_action( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertNotSame( $id, Site_Identity::id() );
		$this->assertTrue( wp_is_uuid( Site_Identity::id(), 4 ) );
		$this->assertSame( '', Connection::api_key() );
		$this->assertFalse( get_option( Connection::STATE_OPTION ) );
		$this->assertSame( 0, Sync_Status::last_success() );
	}

	/**
	 * 同じサイトの見え方の違いでは止まらない。
	 */
	public function test_same_site_does_not_stop() {
		$home = Site_Identity::current_url();

		add_filter(
			'home_url',
			static function () {
				return 'https://example.org/en';
			}
		);
		$this->assertFalse( Site_Identity::has_changed(), 'home_url filter' );

		update_option( Site_Identity::URL_OPTION, $home . '/' );
		$this->assertFalse( Site_Identity::has_changed(), 'trailing slash' );

		$other_scheme = 0 === strpos( $home, 'https://' ) ? 'http://' . substr( $home, 8 ) : 'https://' . substr( $home, 7 );
		update_option( Site_Identity::URL_OPTION, $other_scheme );
		$this->assertFalse( Site_Identity::has_changed(), 'scheme' );

		update_option( Site_Identity::URL_OPTION, strtoupper( $home ) );
		$this->assertFalse( Site_Identity::has_changed(), 'case' );

		update_option( Site_Identity::URL_OPTION, $home . '/sub' );
		$this->assertTrue( Site_Identity::has_changed(), 'path' );
	}

	/**
	 * 通知を描く。
	 *
	 * @param string $screen 画面。
	 * @return string
	 */
	private function notices_on( $screen ) {
		set_current_screen( $screen );
		return $this->output_of( array( Connection_Admin::class, 'render_notices' ) );
	}

	/**
	 * 通知は設定画面とイベントの一覧だけに出る。
	 */
	public function test_notices_only_on_plugin_screens() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Site_Identity::URL_OPTION, 'https://production.example.com' );

		foreach ( array( 'dashboard', 'edit-post', 'edit-page', 'plugins', 'eventwatch_event' ) as $screen ) {
			$this->assertSame( '', $this->notices_on( $screen ), $screen );
		}

		$on_settings = $this->notices_on( 'settings_page_eventwatch' );
		$this->assertStringContainsString( 'production.example.com', $on_settings );
		$this->assertStringContainsString( Connection_Admin::ACTION_COPY, $on_settings );
		$this->assertStringContainsString( Connection_Admin::ACTION_MOVED, $on_settings );

		$on_list = $this->notices_on( 'edit-eventwatch_event' );
		$this->assertStringContainsString( 'production.example.com', $on_list );
		$this->assertStringContainsString( 'page=eventwatch', $on_list );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_COPY, $on_list );
	}

	/**
	 * 直せない編集者には出さない。
	 */
	public function test_notices_only_for_administrators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( Site_Identity::URL_OPTION, 'https://production.example.com' );

		$this->assertSame( '', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * キーなしの通知は一覧にだけ出て、閉じられる。キーなしなら URL の変化を騒がない。
	 */
	public function test_no_key_notice() {
		$user_id = $this->log_in_as_admin();
		update_option( Site_Identity::URL_OPTION, 'https://production.example.com' );

		$this->assertSame( '', $this->notices_on( 'settings_page_eventwatch' ) );

		$html = $this->notices_on( 'edit-eventwatch_event' );
		$this->assertStringContainsString( Connection_Admin::ACTION_HIDE, $html );
		$this->assertStringNotContainsString( 'production.example.com', $html );

		$this->run_action( Connection_Admin::ACTION_HIDE, array( Connection_Admin::class, 'handle_hide_notice' ) );
		$this->assertSame( '1', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );

		remove_all_filters( 'wp_redirect' );
		$this->assertSame( '', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * 失効と停止は通知に出る。つながっている・未確認では出ない。
	 */
	public function test_key_problem_notice() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$this->assertSame( '', $this->notices_on( 'settings_page_eventwatch' ) );

		update_option(
			Connection::STATE_OPTION,
			array(
				'status'       => Connection::UNAUTHORIZED,
				'organization' => '',
				'checked_at'   => 1,
				'key'          => wp_hash( self::KEY ),
			)
		);

		$this->assertStringContainsString( 'revoked', $this->notices_on( 'settings_page_eventwatch' ) );
		$this->assertStringContainsString( 'page=eventwatch', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * 操作のフォームごとに nonce がある。
	 */
	public function test_forms_have_nonces() {
		$this->log_in_as_admin();

		$html = $this->output_of( array( Connection_Admin::class, 'render_forms' ) );

		foreach ( array( Connection_Admin::ACTION_CHECK, Connection_Admin::ACTION_REMOVE_KEY, Connection_Admin::ACTION_MOVED, Connection_Admin::ACTION_COPY ) as $action ) {
			$this->assertStringContainsString( 'id="' . $action . '"', $html );
			$this->assertStringContainsString( wp_create_nonce( $action ), $html );
		}
		$this->assertSame( 4, substr_count( $html, 'admin-post.php' ) );
	}

	/**
	 * 健康状態の欄。
	 */
	public function test_sync_status_field() {
		$html = $this->output_of( array( Settings::class, 'render_sync_status_field' ) );
		$this->assertStringContainsString( 'Not yet', $html );
		$this->assertStringContainsString( 'Waiting to send: 0 events', $html );
		$this->assertStringContainsString( Settings::CRON_HELP_URL, $html );

		update_option( Sync_Status::LAST_SYNC_OPTION, 1790000000 );
		add_filter(
			'eventwatch_sync_pending_count',
			static function () {
				return 1;
			}
		);
		$html = $this->output_of( array( Settings::class, 'render_sync_status_field' ) );
		$this->assertStringNotContainsString( 'Not yet', $html );
		$this->assertStringContainsString( 'Waiting to send: 1 event<', $html );
	}

	/**
	 * 投稿も消す欄。既定はオフで、保存すると削除の処理が読む値になる。
	 */
	public function test_delete_events_field() {
		$this->assertStringNotContainsString( 'checked', $this->output_of( array( Settings::class, 'render_delete_events_field' ) ) );

		update_option( Settings::DELETE_EVENTS, '1' );
		$this->assertTrue( (bool) get_option( 'eventwatch_delete_events_on_uninstall' ) );
		$this->assertStringContainsString( "checked='checked'", $this->output_of( array( Settings::class, 'render_delete_events_field' ) ) );

		update_option( Settings::DELETE_EVENTS, null );
		$this->assertFalse( (bool) get_option( 'eventwatch_delete_events_on_uninstall' ) );
	}

	/**
	 * API を止めても公開と表示は変わらず、表示の流れは EventWatch を呼ばない。
	 */
	public function test_site_does_not_depend_on_the_api() {
		Event_Meta::register();
		update_option( Connection::KEY_OPTION, self::KEY );
		$day = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, wp_timezone() );

		$id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'A & B',
			)
		);
		update_post_meta(
			$id,
			'eventwatch_occurrences',
			array(
				array(
					'start' => $day . ' 13:30',
					'end'   => $day . ' 15:00',
				),
			)
		);
		wp_publish_post( $id );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$list = do_shortcode( '[eventwatch_events]' );
		$this->assertStringContainsString( 'A &amp; B', $list );

		$this->go_to( get_permalink( $id ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringNotContainsString( self::KEY, $head . $list );

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * サイトごとに別のキーとサイト ID を持つ。ネットワーク有効化と新しいサイトで ID ができる。
	 *
	 * @group ms-required
	 */
	public function test_each_site_has_its_own_key_and_id() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();
		$other  = 'ewk_' . str_repeat( 'q', 43 );

		Plugin::activate( true );

		switch_to_blog( $first );
		$first_id = get_option( Site_Identity::ID_OPTION );
		update_option( Connection::KEY_OPTION, self::KEY );
		restore_current_blog();

		switch_to_blog( $second );
		$second_id = get_option( Site_Identity::ID_OPTION );
		update_option( Connection::KEY_OPTION, $other );
		$this->assertSame( $other, Connection::api_key() );
		restore_current_blog();

		$this->assertTrue( wp_is_uuid( $first_id, 4 ) );
		$this->assertTrue( wp_is_uuid( $second_id, 4 ) );
		$this->assertNotSame( $first_id, $second_id );

		switch_to_blog( $first );
		$this->assertSame( self::KEY, Connection::api_key() );
		restore_current_blog();
	}

	/**
	 * ネットワーク全体で有効なときの新しいサイトにも ID ができる。
	 *
	 * @group ms-required
	 */
	public function test_new_site_gets_an_id_when_network_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		add_filter(
			'pre_site_option_active_sitewide_plugins',
			static function () {
				return array( plugin_basename( \EventWatch\PLUGIN_FILE ) => time() );
			}
		);

		$site = self::factory()->blog->create();

		switch_to_blog( $site );
		$this->assertTrue( wp_is_uuid( get_option( Site_Identity::ID_OPTION ), 4 ) );
		restore_current_blog();
	}
}
