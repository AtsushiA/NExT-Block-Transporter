<?php
/**
 * アンインストール時のクリーンアップ処理
 *
 * プラグイン削除時に、一時作業フォルダ(uploads/nbt-tmp/)と
 * 残存するcronイベントを削除する。
 *
 * @package NExT_Block_Transporter
 */

// WordPress本体からのアンインストール呼び出し以外では実行しない。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * フォルダを再帰的に削除する(NBT_FSを読み込まずに完結させるための軽量版)
 *
 * @param string $dir 削除するフォルダの絶対パス。
 * @return void
 */
function nbt_uninstall_delete_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$files = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
	foreach ( $files as $file ) {
		$path = trailingslashit( $dir ) . $file;
		if ( is_dir( $path ) ) {
			nbt_uninstall_delete_dir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 自プラグイン管理下の一時フォルダの削除のため。
}

// 一時作業フォルダを削除する。
$nbt_upload_dir = wp_upload_dir();
$nbt_tmp_dir    = trailingslashit( $nbt_upload_dir['basedir'] ) . 'nbt-tmp';
nbt_uninstall_delete_dir( $nbt_tmp_dir );

// 残存するcronイベントを解除する。
wp_clear_scheduled_hook( 'nbt_cleanup_tmp' );
