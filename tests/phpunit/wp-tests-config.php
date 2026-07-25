<?php
/**
 * PHPUnitからWordPressテストスイートを起動するための設定ファイル
 *
 * このプラグインは常に本物のWordPress環境(wp-content/plugins/配下)で開発される前提のため、
 * ABSPATHは自身のパスから相対的に導出する(環境ごとの絶対パスをここに書かない)。
 * DB接続情報はマシン固有のため、実行時に環境変数(WP_TESTS_DB_*)で指定する
 * (READMEに設定例を記載)。
 *
 * @package NExT_Block_Transporter
 */

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'NExT Block Transporter Test' );

// wp-phpunitのincludes/bootstrap.phpはWP_PHP_BINARYをescapeshellarg()せずsystem()へ渡すため、
// パスにスペースを含む環境(例: macOS版Local(Local by Flywheel)のPHPバイナリは
// "Application Support" 配下にあり、install.phpの起動に失敗する)ではテストDBの初期化が壊れる。
// スペースを含まないシンボリックリンクを都度用意して回避する。
$php_binary = PHP_BINARY;
if ( false !== strpos( $php_binary, ' ' ) ) {
	$php_binary_link = sys_get_temp_dir() . '/nbt-phpunit-php-bin';
	if ( ! is_link( $php_binary_link ) || readlink( $php_binary_link ) !== $php_binary ) {
		if ( file_exists( $php_binary_link ) || is_link( $php_binary_link ) ) {
			wp_delete_file( $php_binary_link );
		}
		symlink( $php_binary, $php_binary_link );
	}
	$php_binary = $php_binary_link;
}
define( 'WP_PHP_BINARY', $php_binary );

// wp-content/plugins/NExT-Block-Transporter/tests/phpunit/wp-tests-config.php から
// 6階層上(tests→phpunit→NExT-Block-Transporter→plugins→wp-content→public)がWordPressのルート(ABSPATH)。
$abspath = getenv( 'WP_TESTS_ABSPATH' );
define( 'ABSPATH', ( $abspath ? $abspath : dirname( __DIR__, 5 ) ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'nbt_phpunit_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// wp-phpunitのincludes/bootstrap.phpがこの変数名をそのままグローバルスコープで要求するため、
// $table_prefixのオーバーライドはこのファイルの役割上避けられない。
$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp-phpunitのテストブートストラップ仕様上必須のため。

define( 'WP_DEBUG', true );
