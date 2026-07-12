<?php
/**
 * ファイルシステム関連の共通ユーティリティ
 *
 * 一時作業フォルダの用意やパス検証など、エクスポート・インポート双方で
 * 使うファイル操作のセキュリティ境界をここに集約する。
 *
 * @package NExT_Block_Transporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ファイルシステム操作をまとめたユーティリティクラス
 */
class NBT_FS {

	/**
	 * 一時作業フォルダ名(uploads配下)
	 */
	const TMP_DIRNAME = 'nbt-tmp';

	/**
	 * 一時ファイルの保持時間(秒)。これより古いものはクリーンアップ対象とする。
	 */
	const TMP_TTL = 3600;

	/**
	 * 一時作業フォルダの絶対パスを返す(無ければ作成する)
	 *
	 * 直リスティングやWeb経由の実行を防ぐため、初回作成時に .htaccess と
	 * 空の index.php を設置する。
	 *
	 * @return string 一時作業フォルダの絶対パス(末尾スラッシュ付き)
	 */
	public static function get_tmp_dir() {
		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . self::TMP_DIRNAME . '/';

		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
		}

		// ディレクトリリスティング無効化用の index.php を設置する。
		$index_file = $tmp_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- 自プラグイン管理下の一時フォルダ保護ファイルの設置のため。
		}

		// Apache環境向けに、リスティング無効化とPHP等の実行防止用の .htaccess を設置する。
		// ZIPの直リンクダウンロードは維持したいので、静的ファイルへのアクセスは許可し、
		// スクリプト実行ファイル(.php/.phtml/.phar)のみアクセスを拒否する。
		$htaccess_file = $tmp_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess  = "Options -Indexes\n";
			$htaccess .= "<FilesMatch \"(?i)\\.(php|phtml|phar)$\">\n";
			$htaccess .= "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n";
			$htaccess .= "\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n";
			$htaccess .= "</FilesMatch>\n";
			file_put_contents( $htaccess_file, $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- 自プラグイン管理下の一時フォルダ保護ファイルの設置のため。
		}

		return $tmp_dir;
	}

	/**
	 * 一時作業フォルダ内の古いファイル・フォルダを削除する
	 *
	 * @return void
	 */
	public static function cleanup_expired() {
		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . self::TMP_DIRNAME . '/';

		if ( ! is_dir( $tmp_dir ) ) {
			return;
		}

		$now     = time();
		$entries = array_diff( (array) scandir( $tmp_dir ), array( '.', '..', 'index.php', '.htaccess' ) );

		foreach ( $entries as $entry ) {
			$path  = $tmp_dir . $entry;
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 競合で消えた場合にfalseを受けるための抑制。

			// TTLを超えていないもの、mtimeが取れないものは触らない。
			if ( ! $mtime || ( $now - $mtime ) < self::TMP_TTL ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				self::delete_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * フォルダを再帰的に削除する
	 *
	 * @param string $dir 削除するフォルダの絶対パス。
	 * @return void
	 */
	public static function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = trailingslashit( $dir ) . $file;
			if ( is_dir( $path ) ) {
				self::delete_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 自プラグイン管理下の一時フォルダの削除のため。
	}

	/**
	 * 対象パスが基準ディレクトリ内に収まっているかを検証する
	 *
	 * シンボリックリンクや `../` を解決した実パスで判定し、パストラバーサルを防ぐ。
	 *
	 * @param string $path     検証対象のパス(実在しなくてもよい)。
	 * @param string $base_dir 収まっているべき基準ディレクトリの絶対パス。
	 * @return string|false 基準内なら正規化済みの絶対パス、外なら false
	 */
	public static function within_dir( $path, $base_dir ) {
		$real_base = realpath( $base_dir );
		if ( ! $real_base ) {
			return false;
		}
		$real_base = trailingslashit( $real_base );

		// 対象が実在すればシンボリックリンクまで解決した実パスを使い、
		// 実在しない場合は `.` / `..` を字句的に畳んで正規化する
		// (中間ディレクトリが未作成でも判定できるようにするため)。
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			$real_path = self::normalize_path( $path );
		}

		// 正規化した実パスが基準ディレクトリを接頭辞に持つかで内外を判定する。
		if ( 0 !== strpos( trailingslashit( $real_path ), $real_base ) && rtrim( $real_base, '/' ) !== $real_path ) {
			return false;
		}

		return $real_path;
	}

	/**
	 * パス文字列中の `.` / `..` / 連続スラッシュを字句的に畳んで正規化する
	 *
	 * ファイルシステムへはアクセスせず文字列処理のみで解決する。
	 *
	 * @param string $path 正規化するパス。
	 * @return string 正規化済みのパス
	 */
	private static function normalize_path( $path ) {
		$path        = wp_normalize_path( $path );
		$is_absolute = '/' === substr( $path, 0, 1 );
		$segments    = explode( '/', $path );
		$result      = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				// 親参照は直前のセグメントを取り除く(先頭を超える分は捨てる)。
				if ( ! empty( $result ) && '..' !== end( $result ) ) {
					array_pop( $result );
				} elseif ( ! $is_absolute ) {
					$result[] = '..';
				}
				continue;
			}
			$result[] = $segment;
		}

		return ( $is_absolute ? '/' : '' ) . implode( '/', $result );
	}
}
