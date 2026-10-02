<?php
/**
 * Plugin Name:       NExT Block Transporter
 * Plugin URI:        https://next-season.net/
 * Description:       Gutenbergのブロックを画像込みでパッケージ化してエクスポートし、別サイトへインポート(メディア再アップロード・パス修正・ブロック復元)できるようにするプラグイン。
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            NExT-Season
 * Author URI:        https://next-season.net/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       next-block-transporter
 * Domain Path:       /languages
 *
 * @package NExT_Block_Transporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access禁止
}

define( 'NBT_VERSION', '0.4.0' );
define( 'NBT_PLUGIN_FILE', __FILE__ );
define( 'NBT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NBT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NBT_REST_NAMESPACE', 'next-block-transporter/v1' );

/**
 * Autoload includes
 */
require_once NBT_PLUGIN_DIR . 'includes/class-nbt-fs.php';
require_once NBT_PLUGIN_DIR . 'includes/class-nbt-export.php';
require_once NBT_PLUGIN_DIR . 'includes/class-nbt-import.php';
require_once NBT_PLUGIN_DIR . 'includes/class-nbt-rest-controller.php';

/**
 * REST APIルートの登録
 */
add_action(
	'rest_api_init',
	function () {
		( new NBT_Rest_Controller() )->register_routes();
	}
);

/**
 * ブロックエディタ用スクリプト・スタイルの読み込み
 */
add_action(
	'enqueue_block_editor_assets',
	function () {
		$asset_file = NBT_PLUGIN_DIR . 'assets/js/editor.asset.php';
		$deps       = array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-plugins', 'wp-edit-post' );
		// ビルド成果物のasset.phpが無い間はファイル更新時刻をバージョンにしてブラウザキャッシュを確実に無効化する。
		$js_mtime = filemtime( NBT_PLUGIN_DIR . 'assets/js/editor.js' );
		$version  = $js_mtime ? (string) $js_mtime : NBT_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = include $asset_file;
			$deps    = $asset['dependencies'];
			$version = $asset['version'];
		}

		wp_enqueue_script(
			'nbt-editor',
			NBT_PLUGIN_URL . 'assets/js/editor.js',
			$deps,
			$version,
			true
		);

		$css_mtime = filemtime( NBT_PLUGIN_DIR . 'assets/css/editor.css' );
		wp_enqueue_style(
			'nbt-editor',
			NBT_PLUGIN_URL . 'assets/css/editor.css',
			array(),
			$css_mtime ? (string) $css_mtime : NBT_VERSION
		);

		wp_localize_script(
			'nbt-editor',
			'NBT_SETTINGS',
			array(
				'restUrl' => esc_url_raw( rest_url( NBT_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'siteUrl' => site_url(),
			)
		);
	}
);

/**
 * アップロード可能な拡張子として .nxbt (実体はzip) を許可
 * ※ .zip のままでも動作するが、専用拡張子を使いたい場合の例
 */
add_filter(
	'upload_mimes',
	function ( $mimes ) {
		$mimes['nxbt'] = 'application/zip';
		return $mimes;
	}
);

/**
 * 一時作業フォルダの定期クリーンアップ用cronイベント名
 */
define( 'NBT_CLEANUP_HOOK', 'nbt_cleanup_tmp' );

/**
 * 有効化時に日次クリーンアップイベントをスケジュールする
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( NBT_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', NBT_CLEANUP_HOOK );
		}
	}
);

/**
 * 無効化時にクリーンアップイベントを解除する
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( NBT_CLEANUP_HOOK );
	}
);

// cron発火時に期限切れの一時ファイルを削除する。
add_action( NBT_CLEANUP_HOOK, array( 'NBT_FS', 'cleanup_expired' ) );
