<?php
/**
 * ブロックのインポート処理
 *
 * アップロードされたパッケージ(ZIP)を展開し、manifest.jsonを読み込んで
 * メディアファイルをメディアライブラリへ再アップロード、ブロックマークアップ内の
 * URL / attachment IDを新しいものに置き換えて返す。
 *
 * 遅い/リソース制限の厳しいサーバーでは、メディア点数が多い・ファイルが大きいパッケージを
 * 1リクエストで一括処理すると max_execution_time や memory_limit を超えて処理が
 * 途中終了してしまうことがある。これを避けるため、処理をセッション化し
 * 「展開(start)」→「メディア1件ずつの登録(process_next)」→「マークアップ確定(finish)」
 * の複数リクエストに分割して実行できるようにしている。セッション状態は展開先フォルダ内の
 * 状態ファイル(STATE_FILENAME)へ保存し、リクエストをまたいで引き継ぐ。
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
	 * セッション状態を保存するファイル名(展開先フォルダ直下)
	 */
	const STATE_FILENAME = '.nbt-import-state.json';

	/**
	 * セッションIDとして許容する形式(wp_generate_password(20, false)相当の英数字)
	 */
	const SESSION_ID_PATTERN = '/^[A-Za-z0-9]{10,64}$/';

	/**
	 * アップロードされたZIPパッケージを展開し、インポートセッションを開始する
	 *
	 * ZIPの展開とmanifest.jsonの読み込みのみを行い、メディアの登録は行わない
	 * (メディア登録は1件ずつ process_next() で行う)。
	 *
	 * @param string $zip_file_path 一時アップロードされたzipの絶対パス。
	 * @return array{session_id:string, total:int}|WP_Error
	 */
	public function start_session( $zip_file_path ) {
		NBT_FS::raise_processing_limits();

		// 展開先はuploads配下の推測困難なフォルダとし、セッション終了時に必ず削除する。
		$tmp_dir    = NBT_FS::get_tmp_dir();
		$session_id = wp_generate_password( 20, false );
		$extract_to = $tmp_dir . 'extract-' . $session_id . '/';
		wp_mkdir_p( $extract_to );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file_path ) ) {
			NBT_FS::delete_dir( $extract_to );
			return new WP_Error( 'nbt_zip_open_failed', __( 'パッケージファイルを開けませんでした。', 'next-block-transporter' ) );
		}

		// zip-slip(展開先の外へ書き出すエントリ)やzip爆弾を防ぐため、
		// 一括展開せず全エントリを事前検査したうえで安全なものだけ展開する。
		$safe = $this->safe_extract( $zip, $extract_to );
		$zip->close();

		if ( is_wp_error( $safe ) ) {
			NBT_FS::delete_dir( $extract_to );
			return $safe;
		}

		$manifest_path = $extract_to . 'manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			NBT_FS::delete_dir( $extract_to );
			return new WP_Error( 'nbt_manifest_missing', __( 'manifest.jsonが見つかりません。パッケージ形式が不正です。', 'next-block-transporter' ) );
		}

		$manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ZIP展開先のローカルファイル読み込みのため。
		if ( ! is_array( $manifest ) || empty( $manifest['block_markup'] ) || ! is_string( $manifest['block_markup'] ) ) {
			NBT_FS::delete_dir( $extract_to );
			return new WP_Error( 'nbt_manifest_invalid', __( 'manifest.jsonの内容が不正です。', 'next-block-transporter' ) );
		}

		// メディア一覧はv1.0の`media`キーから取得する。
		// フォルダ名変更前の開発中パッケージ用に旧`images`キーもフォールバックで受け付ける。
		if ( isset( $manifest['media'] ) && is_array( $manifest['media'] ) ) {
			$manifest_media = $manifest['media'];
		} elseif ( isset( $manifest['images'] ) && is_array( $manifest['images'] ) ) {
			$manifest_media = $manifest['images'];
		} else {
			$manifest_media = array();
		}

		// エクスポート元で解決できなかったメディア(resolved=false)は登録処理が不要なので、
		// この時点で処理待ちキューから除外しておく(URLはそのまま・imported_mediaにも計上しない)。
		$pending = array_values(
			array_filter(
				$manifest_media,
				function ( $media_item ) {
					return ! empty( $media_item['resolved'] );
				}
			)
		);

		$state = array(
			'block_markup'   => $manifest['block_markup'],
			'pending'        => $pending,
			'url_map'        => array(),
			'id_map'         => array(),
			'imported_media' => array(),
			'sideloaded'     => array(),
		);
		$this->write_state( $extract_to, $state );

		return array(
			'session_id' => $session_id,
			'total'      => count( $pending ),
		);
	}

	/**
	 * セッション内で未処理のメディアを1件だけ処理する
	 *
	 * 1リクエストにつきメディア1件分の登録(ファイルコピー・添付ファイル登録・
	 * 画像サイズ再生成)のみを行う。呼び出し側は remaining が 0 になるまで繰り返し呼ぶ。
	 *
	 * @param string $session_id start_session() が返したセッションID。
	 * @return array{remaining:int, item:array|null}|WP_Error
	 */
	public function process_next( $session_id ) {
		$extract_to = $this->resolve_session_dir( $session_id );
		if ( is_wp_error( $extract_to ) ) {
			return $extract_to;
		}

		// 複数リクエストにまたがる処理中にTTLクリーンアップで消えないよう延命する。
		NBT_FS::touch_dir( $extract_to );

		$state = $this->read_state( $extract_to );
		if ( null === $state ) {
			return new WP_Error( 'nbt_session_invalid', __( 'インポートセッションが見つかりません。最初からやり直してください。', 'next-block-transporter' ) );
		}

		if ( empty( $state['pending'] ) ) {
			// 既に処理済み。呼び出し側の取りこぼしでも安全に完了扱いにする。
			return array(
				'remaining' => 0,
				'item'      => null,
			);
		}

		NBT_FS::raise_processing_limits();

		$media_item  = array_shift( $state['pending'] );
		$item_result = $this->process_media_item( $media_item, $extract_to, $state );

		$this->write_state( $extract_to, $state );

		return array(
			'remaining' => count( $state['pending'] ),
			'item'      => $item_result,
		);
	}

	/**
	 * メディア1件をメディアライブラリへ登録し、状態(url_map/id_map/imported_media)を更新する
	 *
	 * @param array  $media_item manifest.json の media[] 要素1件分。
	 * @param string $extract_to ZIP展開先の絶対パス(末尾スラッシュ付き)。
	 * @param array  $state セッション状態(参照渡しで更新する)。
	 * @return array|null 処理結果の要約(archive_pathが不正でスキップした場合はnull)
	 */
	private function process_media_item( $media_item, $extract_to, &$state ) {
		// archive_path はmanifest(外部入力)由来のため、そのまま結合するとパストラバーサルで
		// 展開先の外(サーバー上の任意ファイル)を指しうる。実パスが展開先内に収まることを検証する。
		if ( empty( $media_item['archive_path'] ) || ! is_string( $media_item['archive_path'] ) ) {
			return null;
		}
		$archive_path = NBT_FS::within_dir( $extract_to . $media_item['archive_path'], $extract_to );
		if ( ! $archive_path || ! is_file( $archive_path ) ) {
			return null;
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
		// WP_Errorはそのままでは状態ファイル(JSON)に保存できないため、成否をプレーンな配列で保持する。
		if ( isset( $state['sideloaded'][ $media_item['archive_path'] ] ) ) {
			$cached = $state['sideloaded'][ $media_item['archive_path'] ];
		} else {
			$new_attachment = $this->sideload_media( $archive_path, $desired_filename );
			if ( is_wp_error( $new_attachment ) ) {
				$cached = array(
					'ok'    => false,
					'error' => $new_attachment->get_error_message(),
				);
			} else {
				$cached = array(
					'ok'  => true,
					'id'  => $new_attachment['id'],
					'url' => $new_attachment['url'],
				);
			}
			$state['sideloaded'][ $media_item['archive_path'] ] = $cached;
		}

		if ( ! $cached['ok'] ) {
			$result                    = array(
				'original_url' => $media_item['original_url'],
				'error'        => $cached['error'],
			);
			$state['imported_media'][] = $result;
			return $result;
		}

		// マークアップ中のURLは、参照されていたサイズスラッグに対応する新URLへ差し替える。
		// (オリジナル画像の登録時に受け入れ側の設定で各サイズが再生成されている)
		$size_slug = ! empty( $media_item['size_slug'] ) && is_string( $media_item['size_slug'] ) ? $media_item['size_slug'] : 'full';
		$new_url   = wp_get_attachment_image_url( $cached['id'], $size_slug );
		if ( ! $new_url ) {
			// 画像以外のメディアや該当サイズが取得できない場合はフルサイズのURLを使う。
			$new_url = $cached['url'];
		}

		// 書き換え用マップに登録する(実際の置換はセッション完了時にブロック単位でまとめて行う)。
		$state['url_map'][ $media_item['original_url'] ] = $new_url;
		if ( ! empty( $media_item['attachment_id'] ) ) {
			$state['id_map'][ (int) $media_item['attachment_id'] ] = (int) $cached['id'];
		}

		$result                    = array(
			'original_url'      => $media_item['original_url'],
			'new_url'           => $new_url,
			'new_attachment_id' => $cached['id'],
		);
		$state['imported_media'][] = $result;

		return $result;
	}

	/**
	 * セッションを完了し、ブロックマークアップを確定する
	 *
	 * 未処理のメディアが残っている場合はエラーを返す(呼び出し側は process_next() を
	 * remaining が 0 になるまで呼び切ってから finish_session() を呼ぶこと)。
	 *
	 * @param string $session_id start_session() が返したセッションID。
	 * @return array{block_markup:string, imported_media:array}|WP_Error
	 */
	public function finish_session( $session_id ) {
		$extract_to = $this->resolve_session_dir( $session_id );
		if ( is_wp_error( $extract_to ) ) {
			return $extract_to;
		}

		$state = $this->read_state( $extract_to );
		if ( null === $state ) {
			return new WP_Error( 'nbt_session_invalid', __( 'インポートセッションが見つかりません。最初からやり直してください。', 'next-block-transporter' ) );
		}

		if ( ! empty( $state['pending'] ) ) {
			return new WP_Error( 'nbt_session_incomplete', __( 'メディアの処理が完了していません。', 'next-block-transporter' ) );
		}

		$block_markup = $state['block_markup'];

		// ブロックをパースし、属性(コメントJSON)とHTML内のメディア参照を書き換えて再シリアライズする。
		// 文字列置換では書き換えられないコメントJSON内の id 属性もここで新IDへ更新される。
		if ( $state['url_map'] || $state['id_map'] ) {
			$blocks       = parse_blocks( $block_markup );
			$blocks       = $this->replace_media_references( $blocks, $state['url_map'], $state['id_map'] );
			$block_markup = serialize_blocks( $blocks );
		}

		NBT_FS::delete_dir( $extract_to );

		return array(
			'block_markup'   => $block_markup,
			'imported_media' => $state['imported_media'],
		);
	}

	/**
	 * セッションIDからセッション展開先フォルダの実パスを検証つきで解決する
	 *
	 * @param string $session_id 検証対象のセッションID。
	 * @return string|WP_Error 展開先フォルダの絶対パス(末尾スラッシュ付き)、不正な場合はWP_Error
	 */
	private function resolve_session_dir( $session_id ) {
		if ( ! is_string( $session_id ) || ! preg_match( self::SESSION_ID_PATTERN, $session_id ) ) {
			return new WP_Error( 'nbt_invalid_session', __( '不正なセッションIDです。', 'next-block-transporter' ) );
		}

		$tmp_dir   = NBT_FS::get_tmp_dir();
		$candidate = $tmp_dir . 'extract-' . $session_id;
		$real_path = NBT_FS::within_dir( $candidate, $tmp_dir );

		if ( ! $real_path || ! is_dir( $real_path ) ) {
			return new WP_Error( 'nbt_session_not_found', __( 'インポートセッションが見つかりません。最初からやり直してください。', 'next-block-transporter' ) );
		}

		return trailingslashit( $real_path );
	}

	/**
	 * セッション状態を状態ファイルから読み込む
	 *
	 * @param string $extract_to セッションの展開先フォルダの絶対パス。
	 * @return array|null 状態(pending/url_map/id_map/imported_media/sideloaded等を含む連想配列)。読み込めない場合はnull
	 */
	private function read_state( $extract_to ) {
		$path = $extract_to . self::STATE_FILENAME;
		if ( ! is_file( $path ) ) {
			return null;
		}

		$data = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 自プラグイン管理下の一時状態ファイル読み込みのため。
		return is_array( $data ) ? $data : null;
	}

	/**
	 * セッション状態を状態ファイルへ保存する
	 *
	 * @param string $extract_to セッションの展開先フォルダの絶対パス。
	 * @param array  $state 保存する状態。
	 * @return void
	 */
	private function write_state( $extract_to, $state ) {
		file_put_contents( $extract_to . self::STATE_FILENAME, wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- 自プラグイン管理下の一時状態ファイル書き込みのため。
	}

	/**
	 * ZIPを展開先の外へ書き出さないよう検査しながら安全に展開する
	 *
	 * Zip-slip(`../` や絶対パスのエントリ名で展開先の外へ書き出す攻撃)と、
	 * zip爆弾(過大なエントリ数・展開後サイズ)を防ぐ。
	 *
	 * @param ZipArchive $zip        オープン済みのZIPアーカイブ。
	 * @param string     $extract_to 展開先の絶対パス(末尾スラッシュ付き)。
	 * @return true|WP_Error 成功時true、危険を検知した場合はWP_Error
	 */
	private function safe_extract( $zip, $extract_to ) {
		// 展開エントリ数・展開後合計サイズの上限(zip爆弾対策)。
		$max_entries    = 2000;
		$max_total_size = 500 * 1024 * 1024;
		$num_files      = $zip->numFiles;

		if ( $num_files > $max_entries ) {
			return new WP_Error( 'nbt_zip_too_many', __( 'パッケージ内のファイル数が多すぎます。', 'next-block-transporter' ) );
		}

		$total_size = 0;
		for ( $i = 0; $i < $num_files; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}

			$entry_name = $stat['name'];

			// 絶対パス・ドライブレター・親参照を含むエントリ名は拒否する。
			if ( '/' === substr( $entry_name, 0, 1 ) || preg_match( '#(^|/)\.\.(/|$)#', $entry_name ) || preg_match( '#^[a-zA-Z]:#', $entry_name ) ) {
				return new WP_Error( 'nbt_zip_unsafe_path', __( 'パッケージ内に不正なパスが含まれています。', 'next-block-transporter' ) );
			}

			// 実パスが展開先の外を指す場合も拒否する(二重チェック)。
			if ( ! NBT_FS::within_dir( $extract_to . $entry_name, $extract_to ) ) {
				return new WP_Error( 'nbt_zip_unsafe_path', __( 'パッケージ内に不正なパスが含まれています。', 'next-block-transporter' ) );
			}

			$total_size += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( $total_size > $max_total_size ) {
				return new WP_Error( 'nbt_zip_too_large', __( 'パッケージの展開後サイズが大きすぎます。', 'next-block-transporter' ) );
			}
		}

		// 全エントリが安全と確認できたので展開する。
		if ( ! $zip->extractTo( $extract_to ) ) {
			return new WP_Error( 'nbt_zip_extract_failed', __( 'パッケージの展開に失敗しました。', 'next-block-transporter' ) );
		}

		return true;
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
		// 拡張子だけでなくファイルの実内容を照合し、拡張子偽装(例: 実体はPHPだが .jpg を名乗る)を防ぐ。
		$check = wp_check_filetype_and_ext( $file_path, $desired_filename );

		// 実内容から判定した正しいファイル名がある場合はそれを採用する。
		if ( ! empty( $check['proper_filename'] ) ) {
			$desired_filename = $check['proper_filename'];
		}

		// 拡張子と実内容の双方から許可されたMIMEタイプを確定できない場合は拒否する。
		$mime_type = $check['type'];
		if ( empty( $mime_type ) || empty( $check['ext'] ) ) {
			return new WP_Error( 'nbt_invalid_filetype', __( '未対応のファイル形式です。', 'next-block-transporter' ) );
		}

		// さらにサイト・ユーザーが許可しているMIMEタイプに限定する。
		if ( ! in_array( $mime_type, array_values( get_allowed_mime_types() ), true ) ) {
			return new WP_Error( 'nbt_disallowed_filetype', __( 'このファイル形式はアップロードが許可されていません。', 'next-block-transporter' ) );
		}

		$upload_dir   = wp_upload_dir();
		$new_filename = wp_unique_filename( $upload_dir['path'], $desired_filename );
		$new_path     = trailingslashit( $upload_dir['path'] ) . $new_filename;

		if ( ! copy( $file_path, $new_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy -- 展開済みローカルファイルをuploadsへ複製するため。
			return new WP_Error( 'nbt_copy_failed', __( 'メディアファイルのコピーに失敗しました。', 'next-block-transporter' ) );
		}

		$attachment = array(
			'post_mime_type' => $mime_type,
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
}
