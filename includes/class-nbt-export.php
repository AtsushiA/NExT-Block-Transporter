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
		// 画像点数・ファイルサイズによってはZIP圧縮(close()時)に時間がかかるため、
		// 遅い/リソース制限の厳しいサーバーでもタイムアウトしにくいよう緩和しておく。
		NBT_FS::raise_processing_limits();

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
	 *
	 * `parse_blocks()` でブロックツリーへ分解し、innerBlocks を再帰的にたどりながら
	 * 各ブロックの以下の箇所からメディア参照を収集する。
	 *   - HTML: `<img src="...">`(+ `wp-image-{id}` クラス)と `background-image:url(...)`
	 *   - ブロック属性(コメントJSON): cover / media-text 等の `url`+`id` / `mediaUrl`+`mediaId`
	 * これにより gallery(image の innerBlocks)・cover(背景画像)・media-text の
	 * 入れ子・background-image ケースを検出できる。同一URLは1エントリに重複排除する。
	 *
	 * @param string $block_markup 検出対象のシリアライズ済みブロックHTML。
	 * @return array<int, array{url:string, attachment_id:int|null}>
	 */
	private function extract_images_from_markup( $block_markup ) {
		$images = array();
		$seen   = array(); // url => $images 内のインデックス。重複排除・attachment_idの後追い補完に使う。

		$this->collect_images_from_blocks( parse_blocks( $block_markup ), $images, $seen );

		return $images;
	}

	/**
	 * ブロック配列を再帰的にたどってメディア参照を収集する
	 *
	 * @param array $blocks parse_blocks() で得たブロック配列。
	 * @param array $images 収集結果(参照渡しで追記)。
	 * @param array $seen   url => インデックス の重複排除マップ(参照渡し)。
	 * @return void
	 */
	private function collect_images_from_blocks( $blocks, &$images, &$seen ) {
		foreach ( $blocks as $block ) {
			// 1. コメントJSON属性(cover の url / media-text の mediaUrl など)から収集する。
			if ( ! empty( $block['blockName'] ) && ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$this->collect_images_from_attrs( $block['blockName'], $block['attrs'], $images, $seen );
			}

			// 2. このブロック自身のHTML(innerBlocksの中身は含まない)から収集する。
			if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$this->collect_images_from_html( $block['innerHTML'], $images, $seen );
			}

			// 3. 入れ子ブロック(gallery→image 等)を再帰的にたどる。
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_images_from_blocks( $block['innerBlocks'], $images, $seen );
			}
		}
	}

	/**
	 * ブロック属性からメディアURLを収集する
	 *
	 * `url` 属性はメディア本体を指すブロック(image/cover/video/audio/file)と、
	 * リンク先を指すブロック(button 等)で意味が異なるため、メディア本体を
	 * 持つコアブロックに限定して読み取り、誤検出を防ぐ。
	 *
	 * @param string $block_name ブロック名(例: core/cover)。
	 * @param array  $attrs      ブロック属性。
	 * @param array  $images     収集結果(参照渡し)。
	 * @param array  $seen       重複排除マップ(参照渡し)。
	 * @return void
	 */
	private function collect_images_from_attrs( $block_name, $attrs, &$images, &$seen ) {
		// ブロック名 => [URL属性キー => ID属性キー]。
		$media_attr_map = array(
			'core/image'      => array( 'url' => 'id' ),
			'core/cover'      => array( 'url' => 'id' ),
			'core/media-text' => array( 'mediaUrl' => 'mediaId' ),
		);

		if ( ! isset( $media_attr_map[ $block_name ] ) ) {
			return;
		}

		foreach ( $media_attr_map[ $block_name ] as $url_key => $id_key ) {
			if ( empty( $attrs[ $url_key ] ) || ! is_string( $attrs[ $url_key ] ) ) {
				continue;
			}
			$attachment_id = ( isset( $attrs[ $id_key ] ) && is_numeric( $attrs[ $id_key ] ) ) ? (int) $attrs[ $id_key ] : null;
			$this->add_image( $attrs[ $url_key ], $attachment_id, $images, $seen );
		}
	}

	/**
	 * HTML断片から `<img>` と `background-image:url(...)` のメディアURLを収集する
	 *
	 * @param string $html   走査対象のHTML。
	 * @param array  $images 収集結果(参照渡し)。
	 * @param array  $seen   重複排除マップ(参照渡し)。
	 * @return void
	 */
	private function collect_images_from_html( $html, &$images, &$seen ) {
		// <img src="..."> — wp-image-{id} クラスがあれば添付IDを優先採用する。
		if ( preg_match_all( '/<img[^>]+>/i', $html, $img_tags ) ) {
			foreach ( $img_tags[0] as $img_tag ) {
				if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
					continue;
				}
				$attachment_id = null;
				if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $id_match ) ) {
					$attachment_id = (int) $id_match[1];
				}
				$this->add_image( $src_match[1], $attachment_id, $images, $seen );
			}
		}

		// background-image:url(...) / background:...url(...)(cover 等の背景画像)。
		if ( preg_match_all( '/background(?:-image)?\s*:[^;"\']*url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $bg_matches ) ) {
			foreach ( $bg_matches[1] as $bg_url ) {
				$this->add_image( $bg_url, null, $images, $seen );
			}
		}
	}

	/**
	 * 収集結果へメディアURLを追加する(URL単位で重複排除)
	 *
	 * 添付IDが未指定の場合はURLから解決を試みる。既出URLの場合は追加せず、
	 * 後から添付IDが判明したときだけ既存エントリを補完する。
	 *
	 * @param string   $url           メディアURL。
	 * @param int|null $attachment_id 判明している添付ファイルID(なければnull)。
	 * @param array    $images        収集結果(参照渡し)。
	 * @param array    $seen          url => インデックス の重複排除マップ(参照渡し)。
	 * @return void
	 */
	private function add_image( $url, $attachment_id, &$images, &$seen ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return;
		}

		if ( ! $attachment_id ) {
			$resolved      = attachment_url_to_postid( $url );
			$attachment_id = $resolved ? $resolved : null;
		}

		if ( isset( $seen[ $url ] ) ) {
			// 既出URL。背景画像→imgタグ等でIDが後から判明した場合のみ補完する。
			$index = $seen[ $url ];
			if ( $attachment_id && empty( $images[ $index ]['attachment_id'] ) ) {
				$images[ $index ]['attachment_id'] = $attachment_id;
			}
			return;
		}

		$seen[ $url ] = count( $images );
		$images[]     = array(
			'url'           => $url,
			'attachment_id' => $attachment_id,
		);
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
