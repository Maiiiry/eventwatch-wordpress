<?php
/**
 * PHPUnit の bootstrap。wp-env が入れる WordPress のテストライブラリの上で流す。
 *
 * @package EventWatch
 */

$eventwatch_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $eventwatch_tests_dir ) {
	throw new RuntimeException( 'WP_TESTS_DIR is not set. Run the tests with: npm run test:php' );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $eventwatch_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/eventwatch.php';
	}
);

require $eventwatch_tests_dir . '/includes/bootstrap.php';
