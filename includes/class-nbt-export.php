<?php
/**
 * ブロックのエクスポート処理
 *
 * 選択されたブロックのシリアライズ済みマークアップを受け取り、
 * 中に含まれる画像(URL / attachment ID)を検出してファイル本体を集め、
 * manifest.json + media/ を含むZIPを生成する。
 * (フォルダ名はPDF・動画など画像以外のメディア対応を見据えて media/ とする)
 *
 * @package NExT_Block_Transporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * エクスポート処理を担うクラス
 */
class NBT_Export {

	/**
	 * ブロックマークアップからエクスポート用ZIPを生成する
	 *
	 * @param string $block_markup シリアライズ済みブロックHTML(複数ブロック可)。
	 * @return array{zip_path:string, zip_url:string, filename:string}|WP_Error
	 */
	public function build_package( $block_markup ) {
		$images = $this->extract_images_from_markup( $block_markup );

		// 生成のたびに期限切れの一時ファイルを掃除しておく(cronが動かない環境の保険)。
		NBT_FS::cleanup_expired();

		$upload_dir = wp_upload_dir();
		$tmp_dir    = NBT_FS::get_tmp_dir();

		// ファイル名は推測困難な長いトークンで生成し、URL列挙による第三者ダウンロードを防ぐ。
		$filename = 'nbt-package-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 20, false ) . '.zip';
		$zip_path = $tmp_dir . $filename;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'nbt_zip_failed', __( 'ZIPファイルの作成に失敗しました。', 'next-block-transporter' ) );
		}

		$manifest_media = array();
		$archived       = array();

		foreach ( $images as $index => $image ) {
			$source = $this->resolve_source_file( $image['url'], $image['attachment_id'] );

			if ( ! $source ) {
				// リモート画像やパス解決できないものはスキップしてログ的にmanifestへ記録。
				$manifest_media[] = array(
					'index'        => $index,
					'original_url' => $image['url'],
					'resolved'     => false,
				);
				continue;
			}

			// 同一添付ファイルが複数サイズで参照されていても、実体はZIPへ1回だけ格納して共有する。
			$dedupe_key = $image['attachment_id'] ? 'a' . $image['attachment_id'] : 'p' . $source['path'];
			if ( isset( $archived[ $dedupe_key ] ) ) {
				$archive_name = $archived[ $dedupe_key ];
			} else {
				$ext          = pathinfo( $source['path'], PATHINFO_EXTENSION );
				$archive_name = 'media/media-' . $index . '.' . $ext;
				$zip->addFile( $source['path'], $archive_name );
				$archived[ $dedupe_key ] = $archive_name;
			}

			$manifest_media[] = array(
				'index'             => $index,
				'original_url'      => $image['url'],
				'original_filename' => wp_basename( $source['path'] ),
				'archive_path'      => $archive_name,
				'attachment_id'     => $image['attachment_id'],
				'size_slug'         => $source['size_slug'],
				'resolved'          => true,
			);
		}

		$manifest = array(
			'format_version' => '1.0',
			'plugin'         => 'next-block-transporter',
			'plugin_version' => NBT_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source_site'    => site_url(),
			'block_markup'   => $block_markup,
			'media'          => $manifest_media,
		);

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();

		return array(
			'zip_path' => $zip_path,
			'zip_url'  => trailingslashit( $upload_dir['baseurl'] ) . NBT_FS::TMP_DIRNAME . '/' . $filename,
			'filename' => $filename,
		);
	}

	/**
	 * ブロックマークアップ内の画像を抽出する
	 * 対応対象: <img src="...">、および data-id / wp-image-{id} クラス
	 *
	 * TODO: gallery / cover / media-text ブロックなど、background-image指定や
	 *       innerBlocks内の再帰探索が必要なケースを追加で対応すること
	 *
	 * @param string $block_markup 検出対象のシリアライズ済みブロックHTML。
	 * @return array<int, array{url:string, attachment_id:int|null}>
	 */
	private function extract_images_from_markup( $block_markup ) {
		$images = array();

		if ( ! preg_match_all( '/<img[^>]+>/i', $block_markup, $img_tags ) ) {
			return $images;
		}

		foreach ( $img_tags[0] as $img_tag ) {
			if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
				continue;
			}
			$url = $src_match[1];

			$attachment_id = null;
			if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $id_match ) ) {
				$attachment_id = (int) $id_match[1];
			} else {
				$attachment_id = attachment_url_to_postid( $url );
				$attachment_id = $attachment_id ? $attachment_id : null;
			}

			$images[] = array(
				'url'           => $url,
				'attachment_id' => $attachment_id,
			);
		}

		return $images;
	}

	/**
	 * エクスポートするファイル実体と、URLが指す画像サイズを解決する
	 *
	 * サムネイル等の縮小版URLが参照されていても、添付ファイルのオリジナル画像を
	 * エクスポート対象とし、URLがどの登録サイズを指しているかを size_slug として返す。
	 * (インポート側で各サイズを再生成し、対応するサイズのURLへ差し替えるため)
	 *
	 * @param string   $url           マークアップ中で参照されているメディアURL。
	 * @param int|null $attachment_id 添付ファイルID(解決できていない場合はnull)。
	 * @return array{path:string, size_slug:string}|false 解決できない場合はfalse
	 */
	private function resolve_source_file( $url, $attachment_id ) {
		// 添付IDが分かる場合はオリジナル画像を優先する。
		if ( $attachment_id ) {
			$original_path = wp_get_original_image_path( $attachment_id );
			if ( ! $original_path ) {
				// 画像以外のメディア(PDF等)向けフォールバック。
				$original_path = get_attached_file( $attachment_id );
			}

			if ( $original_path && file_exists( $original_path ) ) {
				return array(
					'path'      => $original_path,
					'size_slug' => $this->detect_size_slug( $url, $attachment_id ),
				);
			}
		}

		// 添付IDが不明・オリジナルが見つからない場合は、URLが指すファイルそのものを実体として使う。
		$local_path = $this->resolve_local_path( $url );
		if ( ! $local_path || ! file_exists( $local_path ) ) {
			return false;
		}

		return array(
			'path'      => $local_path,
			'size_slug' => 'full',
		);
	}

	/**
	 * マークアップ中のURLが添付ファイルのどの登録サイズを指しているかを判定する
	 *
	 * @param string $url           マークアップ中で参照されているメディアURL。
	 * @param int    $attachment_id 添付ファイルID。
	 * @return string 一致した画像サイズのスラッグ(判定できない場合は full)
	 */
	private function detect_size_slug( $url, $attachment_id ) {
		$url_basename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$meta         = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $slug => $size_info ) {
				if ( isset( $size_info['file'] ) && $size_info['file'] === $url_basename ) {
					return $slug;
				}
			}
		}

		return 'full';
	}

	/**
	 * アップロードURLをサーバー上の実ファイルパスに変換する
	 *
	 * @param string $url 変換対象のメディアURL。
	 * @return string|false
	 */
	private function resolve_local_path( $url ) {
		$upload_dir = wp_upload_dir();

		if ( 0 !== strpos( $url, $upload_dir['baseurl'] ) ) {
			return false;
		}

		$relative  = str_replace( $upload_dir['baseurl'], '', $url );
		$candidate = $upload_dir['basedir'] . $relative;

		// URLに `../` 等が含まれる細工でuploads外(wp-config.php等)を指すのを防ぐため、
		// 実パスがuploadsディレクトリ内に収まっていることを検証する。
		$safe_path = NBT_FS::within_dir( $candidate, $upload_dir['basedir'] );
		if ( ! $safe_path || ! is_file( $safe_path ) ) {
			return false;
		}

		return $safe_path;
	}
}
