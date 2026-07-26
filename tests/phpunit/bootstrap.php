<?php
/**
 * PHPUnitブートストラップファイル
 *
 * Composer経由のWordPressコアテストスイート(wp-phpunit/wp-phpunit)を用いてWordPressをロードし、
 * 本プラグインをmust-useプラグイン相当のタイミングで読み込む。
 *
 * @package NExT_Block_Transporter
 */

// Composerのautoloadを読み込む(wp-phpunit/wp-phpunitの__loaded.php経由でWP_PHPUNIT__DIRも自動設定される)。
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// wp-phpunit/wp-phpunit の Composer autoload(__loaded.php)が WP_PHPUNIT__DIR を自動設定する。
$wp_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

// wp-phpunit本体のwp-tests-config.phpが、この環境変数の指すファイルを実際の設定として読み込む
// (wp-phpunitパッケージ側の仕様であり、実行時のプロセス環境変数として渡す以外に受け渡す手段がない)。
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- wp-phpunitへの設定ファイルパス受け渡しに必須のため。

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * テスト対象プラグインを読み込む(muplugins_loadedのタイミング= wp_insert_attachment 等が使えるようになる前)
 *
 * @return void
 */
function _nbt_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/next-block-transporter.php';
}
tests_add_filter( 'muplugins_loaded', '_nbt_manually_load_plugin' );

require $wp_tests_dir . '/includes/bootstrap.php';
