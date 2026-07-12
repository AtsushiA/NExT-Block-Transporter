<?php
/**
 * ブロックのインポート処理
 *
 * アップロードされたパッケージ(ZIP)を展開し、manifest.jsonを読み込んで
 * メディアファイルをメディアライブラリへ再アップロード、ブロックマークアップ内の
 * URL / attachment IDを新しいものに置き換えて返す。
 *
 * @package NExT_Block_Transporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/**
 * インポート処理を担うクラス
 */
class NBT_Import {

	/**
	 * アップロードされたZIPパッケージを処理する
	 *
	 * @param string $zip_file_path 一時アップロードされたzipの絶対パス。
	 * @return array{block_markup:string, imported_media:array}|WP_Error
	 */
	public function process_package( $zip_file_path ) {
		$upload_dir = wp_upload_dir();
		$extract_to = trailingslashit( $upload_dir['basedir'] ) . 'nbt-tmp/extract-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $extract_to );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file_path ) ) {
			return new WP_Error( 'nbt_zip_open_failed', __( 'パッケージファイルを開けませんでした。', 'next-block-transporter' ) );
		}
		$zip->extractTo( $extract_to );
		$zip->close();

		$manifest_path = $extract_to . 'manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return new WP_Error( 'nbt_manifest_missing', __( 'manifest.jsonが見つかりません。パッケージ形式が不正です。', 'next-block-transporter' ) );
		}

		$manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ZIP展開先のローカルファイル読み込みのため。
		if ( ! is_array( $manifest ) || empty( $manifest['block_markup'] ) ) {
			return new WP_Error( 'nbt_manifest_invalid', __( 'manifest.jsonの内容が不正です。', 'next-block-transporter' ) );
		}

		$block_markup   = $manifest['block_markup'];
		$imported_media = array();
		$url_map        = array();
		$id_map         = array();
		$sideloaded     = array();

		// メディア一覧はv1.0の`media`キーから取得する。
		// フォルダ名変更前の開発中パッケージ用に旧`images`キーもフォールバックで受け付ける。
		if ( isset( $manifest['media'] ) && is_array( $manifest['media'] ) ) {
			$manifest_media = $manifest['media'];
		} elseif ( isset( $manifest['images'] ) && is_array( $manifest['images'] ) ) {
			$manifest_media = $manifest['images'];
		} else {
			$manifest_media = array();
		}

		foreach ( $manifest_media as $media_item ) {
			if ( empty( $media_item['resolved'] ) ) {
				// エクスポート元で解決できなかったメディアはそのまま(URL変更なし)
				continue;
			}

			$archive_path = $extract_to . $media_item['archive_path'];
			if ( ! file_exists( $archive_path ) ) {
				continue;
			}

			// 登録ファイル名はエクスポート元の元ファイル名を優先する。
			// manifestは外部入力のため sanitize_file_name を通し、無効・欠落時(旧形式パッケージ)は
			// ZIP内の連番ファイル名にフォールバックする。
			$desired_filename = '';
			if ( ! empty( $media_item['original_filename'] ) && is_string( $media_item['original_filename'] ) ) {
				$desired_filename = sanitize_file_name( $media_item['original_filename'] );
			}
			if ( '' === $desired_filename ) {
				$desired_filename = basename( $archive_path );
			}

			// 同一添付ファイル由来のエントリ(複数サイズ参照)はZIP内実体を共有しているため、
			// メディアライブラリへの登録は1回だけ行い、結果をキャッシュして使い回す。
			if ( isset( $sideloaded[ $media_item['archive_path'] ] ) ) {
				$new_attachment = $sideloaded[ $media_item['archive_path'] ];
			} else {
				$new_attachment = $this->sideload_media( $archive_path, $desired_filename );
				$sideloaded[ $media_item['archive_path'] ] = $new_attachment;
			}

			if ( is_wp_error( $new_attachment ) ) {
				$imported_media[] = array(
					'original_url' => $media_item['original_url'],
					'error'        => $new_attachment->get_error_message(),
				);
				continue;
			}

			// マークアップ中のURLは、参照されていたサイズスラッグに対応する新URLへ差し替える。
			// (オリジナル画像の登録時に受け入れ側の設定で各サイズが再生成されている)
			$size_slug = ! empty( $media_item['size_slug'] ) && is_string( $media_item['size_slug'] ) ? $media_item['size_slug'] : 'full';
			$new_url   = wp_get_attachment_image_url( $new_attachment['id'], $size_slug );
			if ( ! $new_url ) {
				// 画像以外のメディアや該当サイズが取得できない場合はフルサイズのURLを使う。
				$new_url = $new_attachment['url'];
			}

			// 書き換え用マップに登録する(実際の置換はループ後にブロック単位でまとめて行う)。
			$url_map[ $media_item['original_url'] ] = $new_url;
			if ( ! empty( $media_item['attachment_id'] ) ) {
				$id_map[ (int) $media_item['attachment_id'] ] = (int) $new_attachment['id'];
			}

			$imported_media[] = array(
				'original_url'      => $media_item['original_url'],
				'new_url'           => $new_url,
				'new_attachment_id' => $new_attachment['id'],
			);
		}

		// ブロックをパースし、属性(コメントJSON)とHTML内のメディア参照を書き換えて再シリアライズする。
		// 文字列置換では書き換えられないコメントJSON内の id 属性もここで新IDへ更新される。
		if ( $url_map || $id_map ) {
			$blocks       = parse_blocks( $block_markup );
			$blocks       = $this->replace_media_references( $blocks, $url_map, $id_map );
			$block_markup = serialize_blocks( $blocks );
		}

		$this->cleanup_dir( $extract_to );

		return array(
			'block_markup'   => $block_markup,
			'imported_media' => $imported_media,
		);
	}

	/**
	 * ブロック配列を再帰的に走査し、メディア参照を新しいものへ書き換える
	 *
	 * 書き換え対象:
	 * - ブロック属性(コメントJSON): `id` / `mediaId` / `ids` と、文字列属性値に含まれる旧URL
	 * - HTML(innerHTML / innerContent): 旧URL と `wp-image-{旧ID}` クラス
	 *
	 * @param array $blocks  parse_blocks() で得たブロック配列。
	 * @param array $url_map 旧URL => 新URL のマップ。
	 * @param array $id_map  旧attachment ID => 新attachment ID のマップ。
	 * @return array 書き換え後のブロック配列
	 */
	private function replace_media_references( $blocks, $url_map, $id_map ) {
		foreach ( $blocks as &$block ) {
			// コメントJSON部分(attrs)を書き換える。
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$block['attrs'] = $this->replace_media_in_attrs( $block['attrs'], $url_map, $id_map );
			}

			// HTML部分を書き換える。serialize_blocks() は innerContent を出力に使うため両方更新する。
			$block['innerHTML'] = $this->replace_media_in_html( $block['innerHTML'], $url_map, $id_map );
			foreach ( $block['innerContent'] as $i => $chunk ) {
				// innerBlocks の挿入位置を表す null はそのまま維持する。
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $i ] = $this->replace_media_in_html( $chunk, $url_map, $id_map );
				}
			}

			// 入れ子ブロック(innerBlocks)は再帰的に処理する。
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_media_references( $block['innerBlocks'], $url_map, $id_map );
			}
		}

		return $blocks;
	}

	/**
	 * ブロック属性配列内のメディア参照(attachment ID・URL)を書き換える
	 *
	 * @param array $attrs   ブロック属性(入れ子の配列を含む)。
	 * @param array $url_map 旧URL => 新URL のマップ。
	 * @param array $id_map  旧attachment ID => 新attachment ID のマップ。
	 * @return array 書き換え後の属性配列
	 */
	private function replace_media_in_attrs( $attrs, $url_map, $id_map ) {
		foreach ( $attrs as $key => $value ) {
			if ( ( 'id' === $key || 'mediaId' === $key ) && is_numeric( $value ) && isset( $id_map[ (int) $value ] ) ) {
				// image / cover / media-text 等が持つ attachment ID 属性を新IDへ差し替える。
				$attrs[ $key ] = $id_map[ (int) $value ];
			} elseif ( 'ids' === $key && is_array( $value ) ) {
				// 旧ギャラリー形式などの attachment ID 配列を新IDへ差し替える。
				foreach ( $value as $i => $id ) {
					if ( is_numeric( $id ) && isset( $id_map[ (int) $id ] ) ) {
						$value[ $i ] = $id_map[ (int) $id ];
					}
				}
				$attrs[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				// 入れ子の属性配列は再帰的に処理する。
				$attrs[ $key ] = $this->replace_media_in_attrs( $value, $url_map, $id_map );
			} elseif ( is_string( $value ) ) {
				// url 等の文字列属性に含まれる旧URLを置換する。
				$attrs[ $key ] = strtr( $value, $url_map );
			}
		}

		return $attrs;
	}

	/**
	 * HTML文字列内の旧URLと wp-image-{旧ID} クラスを書き換える
	 *
	 * @param string $html    書き換え対象のHTML。
	 * @param array  $url_map 旧URL => 新URL のマップ。
	 * @param array  $id_map  旧attachment ID => 新attachment ID のマップ。
	 * @return string 書き換え後のHTML
	 */
	private function replace_media_in_html( $html, $url_map, $id_map ) {
		if ( '' === $html ) {
			return $html;
		}

		// 旧URL → 新URLへ置換する。
		// strtr() は最長一致かつ一括置換のため、前方一致の誤置換や置換結果への再置換(連鎖置換)が起きない。
		$html = strtr( $html, $url_map );

		// wp-image-{旧ID} → wp-image-{新ID}へ置換する。
		// (?!\d) で旧IDが別IDの一部にマッチする誤置換(例: 旧ID 12 と wp-image-123)を防ぎ、
		// コールバックでの一括置換により連鎖置換(新IDが別の旧IDと衝突するケース)も防ぐ。
		$html = preg_replace_callback(
			'/\bwp-image-(\d+)(?!\d)/',
			function ( $matches ) use ( $id_map ) {
				$old_id = (int) $matches[1];
				return isset( $id_map[ $old_id ] ) ? 'wp-image-' . $id_map[ $old_id ] : $matches[0];
			},
			$html
		);

		return $html;
	}

	/**
	 * ローカルファイルをメディアライブラリへ登録する
	 * (media_handle_sideloadは$_FILES前提のため、ファイルパスから直接登録する版)
	 *
	 * @param string $file_path        コピー元ファイルの絶対パス。
	 * @param string $desired_filename 登録時に希望するファイル名。
	 * @return array{id:int, url:string}|WP_Error
	 */
	private function sideload_media( $file_path, $desired_filename ) {
		$filetype = wp_check_filetype( $desired_filename );
		if ( empty( $filetype['type'] ) ) {
			return new WP_Error( 'nbt_invalid_filetype', __( '未対応のファイル形式です。', 'next-block-transporter' ) );
		}

		$upload_dir   = wp_upload_dir();
		$new_filename = wp_unique_filename( $upload_dir['path'], $desired_filename );
		$new_path     = trailingslashit( $upload_dir['path'] ) . $new_filename;

		if ( ! copy( $file_path, $new_path ) ) {
			return new WP_Error( 'nbt_copy_failed', __( 'メディアファイルのコピーに失敗しました。', 'next-block-transporter' ) );
		}

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( pathinfo( $new_filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $new_path );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $new_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		return array(
			'id'  => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * 展開用一時フォルダを削除する
	 *
	 * @param string $dir 削除する一時フォルダの絶対パス。
	 */
	private function cleanup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->cleanup_dir( $path ) : wp_delete_file( $path );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 自プラグイン管理下の一時展開フォルダの削除のため。
	}
}
