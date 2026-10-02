<?php
/**
 * NBT_Import のテスト
 *
 * 「遅い/リソース制限の厳しいサーバーで、メディア点数が多い・容量が大きいパッケージの
 * インポートがタイムアウトして失敗する」不具合の修正(1リクエスト1メディアのチャンク処理化)
 * を検証する。
 *
 * @package NExT_Block_Transporter
 */

/**
 * NBT_Import::start_session() / process_next() / finish_session() のテストクラス
 */
class NBT_Import_Test extends WP_UnitTestCase {

	/**
	 * テストで作成した添付ファイルID(tearDownで実体ファイルごと削除する)
	 *
	 * @var int[]
	 */
	private $created_attachment_ids = array();

	/**
	 * 各テスト後に、作成した添付ファイルと一時作業フォルダ(nbt-tmp)の中身を削除する
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->created_attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$this->created_attachment_ids = array();

		$tmp_dir = NBT_FS::get_tmp_dir();
		foreach ( array_diff( (array) scandir( $tmp_dir ), array( '.', '..', 'index.php', '.htaccess' ) ) as $entry ) {
			$path = $tmp_dir . $entry;
			if ( is_dir( $path ) ) {
				NBT_FS::delete_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		parent::tear_down();
	}

	/**
	 * 指定件数の画像添付ファイルとそれらを参照するブロックマークアップからエクスポートZIPを作る
	 *
	 * テストは同一サイト内でエクスポート→インポートを行うため、そのままでは「インポート先に
	 * 同じメディアが既にある」状態になり、既存メディアの採用(重複登録の回避)が働く。
	 * 別サイトへの移行(新規登録)を検証する場合は、エクスポート後に元の添付ファイルを削除して
	 * インポート先に存在しない状態を再現する。
	 *
	 * @param int  $count            含める画像の件数。
	 * @param bool $delete_originals エクスポート後に元の添付ファイルを削除するか(別サイトへの移行を再現)。
	 * @return array{zip_path:string, attachment_ids:int[]} エクスポートしたZIPのパスと元添付ファイルID一覧
	 */
	private function build_test_package( $count, $delete_originals = true ) {
		$fixtures = array( 'canola.jpg', 'test-image-3.jpg', 'test-image-4.png', 'codeispoetry.png' );

		$attachment_ids = array();
		$markup         = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$fixture = $fixtures[ $i % count( $fixtures ) ];
			$id      = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $fixture );
			$this->assertIsInt( $id, "前提: フィクスチャ画像 {$fixture} から添付ファイルを作成できること" );
			$attachment_ids[]               = $id;
			$this->created_attachment_ids[] = $id;

			$url     = wp_get_attachment_url( $id );
			$markup .= '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} -->'
				. '<figure class="wp-block-image size-full"><img src="' . esc_url( $url ) . '" class="wp-image-' . $id . '"/></figure>'
				. '<!-- /wp:image -->';
		}

		$package = ( new NBT_Export() )->build_package( $markup );
		$this->assertIsArray( $package, '前提: テスト用パッケージ(ZIP)の生成に成功すること' );

		if ( $delete_originals ) {
			foreach ( $attachment_ids as $id ) {
				wp_delete_attachment( $id, true );
			}
		}

		return array(
			'zip_path'       => $package['zip_path'],
			'attachment_ids' => $attachment_ids,
		);
	}

	/**
	 * NBT_Import::start_session() が有効なZIPからセッションを開始し、不正なパスはWP_Errorになることを確認する
	 *
	 * @return void
	 */
	public function test_start_session() {
		$package  = $this->build_test_package( 2 );
		$importer = new NBT_Import();

		$result = $importer->start_session( $package['zip_path'] );

		$this->assertIsArray( $result, '正常系: 有効なZIPの場合 => 配列が返ること' );
		$this->assertSame( 2, $result['total'], '正常系: メディア2件のパッケージの場合 => totalが2になること' );
		$this->assertNotEmpty( $result['session_id'], '正常系: session_idが発行されること' );

		$invalid_result = $importer->start_session( '/path/does/not/exist-' . wp_generate_password( 8, false ) . '.zip' );

		$this->assertWPError( $invalid_result, '異常系: 存在しないZIPパスの場合 => WP_Errorが返ること' );
		$this->assertSame( 'nbt_zip_open_failed', $invalid_result->get_error_code() );
	}

	/**
	 * NBT_Import::process_next() がメディアを1件ずつ処理し、完了後は冪等に振る舞うことを確認する
	 *
	 * このプラグインの不具合修正の核心(1リクエストで全メディアを一括処理せず、
	 * 呼び出し1回につき1件だけ処理する)を検証する。
	 *
	 * @return void
	 */
	public function test_process_next_processes_exactly_one_media_item_per_call() {
		$media_count = 3;
		$package     = $this->build_test_package( $media_count );
		$importer    = new NBT_Import();

		$start = $importer->start_session( $package['zip_path'] );
		$this->assertSame( $media_count, $start['total'] );

		$remaining          = $start['total'];
		$new_attachment_ids = array();
		$calls              = 0;

		while ( $remaining > 0 ) {
			++$calls;
			$before = $remaining;
			$result = $importer->process_next( $start['session_id'] );

			$this->assertIsArray( $result, "process_next {$calls}回目 => 配列が返ること" );
			$this->assertSame( $before - 1, $result['remaining'], "process_next {$calls}回目 => remainingが1だけ減ること(一括処理されていないこと)" );
			$this->assertIsArray( $result['item'], "process_next {$calls}回目 => 1件分の処理結果が返ること" );
			$this->assertArrayHasKey( 'new_attachment_id', $result['item'] );

			$new_attachment_ids[]           = $result['item']['new_attachment_id'];
			$this->created_attachment_ids[] = $result['item']['new_attachment_id'];
			$remaining                      = $result['remaining'];
		}

		$this->assertSame( $media_count, $calls, "メディア{$media_count}件のパッケージの場合 => process_nextを{$media_count}回呼ぶ必要があること" );
		$this->assertCount( $media_count, array_unique( $new_attachment_ids ), '呼び出しのたびに別々の添付ファイルが1件ずつ登録されること' );

		// 完了後(pendingが空)に呼んでも安全に完了扱いを返すこと(取りこぼし時の冪等性)。
		$idempotent_result = $importer->process_next( $start['session_id'] );
		$this->assertSame( 0, $idempotent_result['remaining'], '正常系: 処理済みセッションに再度呼んだ場合 => remainingは0のまま' );
		$this->assertNull( $idempotent_result['item'], '正常系: 処理済みセッションに再度呼んだ場合 => itemはnull' );
	}

	/**
	 * NBT_Import::finish_session() が全メディア登録後にマークアップを書き換え、セッションフォルダを削除することを確認する
	 *
	 * @return void
	 */
	public function test_finish_session_rewrites_markup_and_cleans_up() {
		$package  = $this->build_test_package( 2 );
		$importer = new NBT_Import();

		$start        = $importer->start_session( $package['zip_path'] );
		$original_ids = $package['attachment_ids'];

		$new_ids = array();
		for ( $i = 0; $i < $start['total']; $i++ ) {
			$result                         = $importer->process_next( $start['session_id'] );
			$new_ids[]                      = $result['item']['new_attachment_id'];
			$this->created_attachment_ids[] = $result['item']['new_attachment_id'];
		}

		$finish = $importer->finish_session( $start['session_id'] );

		$this->assertIsArray( $finish, '正常系: 全メディア処理後にfinishした場合 => 配列が返ること' );
		$this->assertCount( 2, $finish['imported_media'] );

		foreach ( $new_ids as $index => $new_id ) {
			$this->assertStringContainsString(
				'wp-image-' . $new_id,
				$finish['block_markup'],
				'書き換え後のマークアップに新しい添付ファイルIDのクラスが含まれること'
			);
		}
		foreach ( $original_ids as $original_id ) {
			$this->assertStringNotContainsString(
				'wp-image-' . $original_id,
				$finish['block_markup'],
				'書き換え後のマークアップに元(エクスポート元)の添付ファイルIDが残っていないこと'
			);
		}

		// セッションの展開先フォルダが削除されていること。
		$tmp_dir  = NBT_FS::get_tmp_dir();
		$sessions = glob( $tmp_dir . 'extract-*', GLOB_ONLYDIR );
		$this->assertSame( array(), $sessions, 'finish_session() 完了後はセッションの一時フォルダが残っていないこと' );
	}

	/**
	 * NBT_Import::finish_session() は未処理のメディアが残っている間はエラーを返すことを確認する
	 *
	 * @return void
	 */
	public function test_finish_session_returns_error_when_media_still_pending() {
		$package  = $this->build_test_package( 2 );
		$importer = new NBT_Import();

		$start = $importer->start_session( $package['zip_path'] );
		// 1件も process_next() を呼ばないまま finish_session() を呼ぶ。
		$result = $importer->finish_session( $start['session_id'] );

		$this->assertWPError( $result, '異常系: メディア未処理のままfinishした場合 => WP_Errorが返ること' );
		$this->assertSame( 'nbt_session_incomplete', $result->get_error_code() );
	}

	/**
	 * NBT_Import::process_next() / finish_session() が不正なsession_idを検証つきで拒否することを確認する
	 *
	 * @return void
	 */
	public function test_process_next_and_finish_session_reject_invalid_session_id() {
		$test_cases = array(
			array(
				'test_condition_name' => 'パストラバーサル文字列の場合 => nbt_invalid_session',
				'session_id'          => '../../etc/passwd',
				'expected_code'       => 'nbt_invalid_session',
			),
			array(
				'test_condition_name' => '空文字の場合 => nbt_invalid_session',
				'session_id'          => '',
				'expected_code'       => 'nbt_invalid_session',
			),
			array(
				'test_condition_name' => '形式は正しいが存在しないセッションの場合 => nbt_session_not_found',
				'session_id'          => str_repeat( 'a', 20 ),
				'expected_code'       => 'nbt_session_not_found',
			),
		);

		$importer = new NBT_Import();

		foreach ( $test_cases as $case ) {
			$next_result = $importer->process_next( $case['session_id'] );
			$this->assertWPError( $next_result, 'process_next(): ' . $case['test_condition_name'] );
			$this->assertSame( $case['expected_code'], $next_result->get_error_code(), 'process_next(): ' . $case['test_condition_name'] );

			$finish_result = $importer->finish_session( $case['session_id'] );
			$this->assertWPError( $finish_result, 'finish_session(): ' . $case['test_condition_name'] );
			$this->assertSame( $case['expected_code'], $finish_result->get_error_code(), 'finish_session(): ' . $case['test_condition_name'] );
		}
	}

	/**
	 * NBT_Import::process_next() は、manifestのarchive_pathが展開先フォルダの外を指す
	 * (パストラバーサル)場合に、そのメディアを安全にスキップする(登録せずremainingだけ減らす)ことを確認する
	 *
	 * @return void
	 */
	public function test_process_next_skips_media_item_with_unsafe_archive_path() {
		$id                             = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->created_attachment_ids[] = $id;
		$url                            = wp_get_attachment_url( $id );

		$markup = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} -->'
			. '<figure class="wp-block-image size-full"><img src="' . esc_url( $url ) . '" class="wp-image-' . $id . '"/></figure>'
			. '<!-- /wp:image -->';

		$manifest = array(
			'format_version' => '1.0',
			'plugin'         => 'next-block-transporter',
			'plugin_version' => NBT_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source_site'    => site_url(),
			'block_markup'   => $markup,
			'media'          => array(
				array(
					'index'             => 0,
					'original_url'      => $url,
					'original_filename' => 'evil.php',
					// 展開先フォルダの外(uploads直下)を指すパストラバーサル。
					'archive_path'      => '../../evil.php',
					'attachment_id'     => $id,
					'size_slug'         => 'full',
					'resolved'          => true,
				),
			),
		);

		$zip_path = trailingslashit( NBT_FS::get_tmp_dir() ) . 'nbt-test-unsafe-' . wp_generate_password( 8, false ) . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		$zip->close();

		$importer = new NBT_Import();
		$start    = $importer->start_session( $zip_path );

		$this->assertSame( 1, $start['total'], '前提: resolved=trueのメディア1件がpendingに含まれること' );

		$result = $importer->process_next( $start['session_id'] );

		$this->assertSame( 0, $result['remaining'], '不正なarchive_pathでもpendingの件数としては消化されること' );
		$this->assertNull( $result['item'], '展開先の外を指すarchive_pathの場合 => 登録されずitemはnullになること' );

		$finish = $importer->finish_session( $start['session_id'] );
		$this->assertSame( array(), $finish['imported_media'], '不正なarchive_pathのメディアはimported_mediaに計上されないこと' );
		$this->assertStringContainsString( 'wp-image-' . $id, $finish['block_markup'], '登録に失敗した場合、マークアップ中のURL/IDは元のまま維持されること' );

		// テスト用にuploads直下へ書き込もうとした形跡(evil.php)が実際には作られていないことを確認する。
		$upload_dir = wp_upload_dir();
		$this->assertFileDoesNotExist( trailingslashit( $upload_dir['basedir'] ) . 'evil.php' );
	}

	/**
	 * NBT_Import::start_session() は、resolved=falseのメディアをpending(処理対象)に含めず、
	 * URLもそのまま維持することを確認する
	 *
	 * @return void
	 */
	public function test_start_session_excludes_unresolved_media_from_pending() {
		$markup = '<!-- wp:image {} --><figure class="wp-block-image"><img src="https://example.com/remote-image.jpg"/></figure><!-- /wp:image -->';

		$manifest = array(
			'format_version' => '1.0',
			'plugin'         => 'next-block-transporter',
			'plugin_version' => NBT_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source_site'    => site_url(),
			'block_markup'   => $markup,
			'media'          => array(
				array(
					'index'        => 0,
					'original_url' => 'https://example.com/remote-image.jpg',
					'resolved'     => false,
				),
			),
		);

		$zip_path = trailingslashit( NBT_FS::get_tmp_dir() ) . 'nbt-test-unresolved-' . wp_generate_password( 8, false ) . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		$zip->close();

		$importer = new NBT_Import();
		$start    = $importer->start_session( $zip_path );

		$this->assertSame( 0, $start['total'], 'resolved=falseのメディアはpendingの件数に含まれないこと' );

		$finish = $importer->finish_session( $start['session_id'] );
		$this->assertStringContainsString( 'https://example.com/remote-image.jpg', $finish['block_markup'], '解決できなかったメディアのURLは書き換えられずそのまま残ること' );
	}

	/**
	 * パッケージ内メディアのファイル実体と、指定したmanifest.media[]でテスト用ZIPを作る
	 *
	 * @param string $markup      ブロックマークアップ。
	 * @param array  $media_items manifest.media[] の要素一覧(archive_path は media/media-{index}.{ext})。
	 * @param array  $files       archive_path => 格納するローカルファイルの絶対パス。
	 * @return string 作成したZIPの絶対パス
	 */
	private function build_custom_package( $markup, $media_items, $files ) {
		$manifest = array(
			'format_version' => '1.0',
			'plugin'         => 'next-block-transporter',
			'plugin_version' => NBT_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source_site'    => site_url(),
			'block_markup'   => $markup,
			'media'          => $media_items,
		);

		$zip_path = trailingslashit( NBT_FS::get_tmp_dir() ) . 'nbt-test-custom-' . wp_generate_password( 8, false ) . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		foreach ( $files as $archive_path => $local_path ) {
			$zip->addFile( $local_path, $archive_path );
		}
		$zip->close();

		return $zip_path;
	}

	/**
	 * セッションの全メディアを処理してfinishし、finishの結果とprocess_nextの処理結果一覧を返す
	 *
	 * @param string $zip_path インポートするZIPの絶対パス。
	 * @return array{finish:array, items:array} finish_session() の結果と process_next() の item 一覧
	 */
	private function run_import( $zip_path ) {
		$importer = new NBT_Import();
		$start    = $importer->start_session( $zip_path );
		$this->assertIsArray( $start, '前提: インポートセッションを開始できること' );

		$items = array();
		for ( $i = 0; $i < $start['total']; $i++ ) {
			$result  = $importer->process_next( $start['session_id'] );
			$items[] = $result['item'];
			if ( ! empty( $result['item']['new_attachment_id'] ) ) {
				$this->created_attachment_ids[] = $result['item']['new_attachment_id'];
			}
		}

		return array(
			'finish' => $importer->finish_session( $start['session_id'] ),
			'items'  => $items,
		);
	}

	/**
	 * 現在の添付ファイル件数を返す
	 *
	 * @return int
	 */
	private function count_attachments() {
		// wp_count_posts() はオブジェクトキャッシュされ添付ファイル登録直後の件数を反映しないため、都度問い合わせる。
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		return count( $ids );
	}

	/**
	 * インポート先にポストIDとファイル名が同一のメディアがある場合、再登録せず既存の添付ファイルを採用することを確認する
	 *
	 * (ステージング→本番のような同一サイト系統間の移行で、メディアが重複登録されないこと)
	 *
	 * @return void
	 */
	public function test_import_reuses_existing_attachment_when_id_and_filename_match() {
		$package      = $this->build_test_package( 2, false );
		$original_ids = $package['attachment_ids'];

		$count_before = $this->count_attachments();
		$import       = $this->run_import( $package['zip_path'] );

		$this->assertSame( $count_before, $this->count_attachments(), 'ポストIDとファイル名が一致する場合 => 新しい添付ファイルが登録されないこと' );

		foreach ( $import['items'] as $index => $item ) {
			$this->assertSame( $original_ids[ $index ], $item['new_attachment_id'], 'ポストIDとファイル名が一致する場合 => 既存の添付ファイルIDが採用されること' );
			$this->assertTrue( $item['reused'], 'ポストIDとファイル名が一致する場合 => reusedがtrueになること' );
			$this->assertSame( wp_get_attachment_url( $original_ids[ $index ] ), $item['new_url'], '既存の添付ファイルのURLが使われること' );
		}

		foreach ( $original_ids as $original_id ) {
			$this->assertStringContainsString( 'wp-image-' . $original_id, $import['finish']['block_markup'], '書き換え後のマークアップは既存の添付ファイルIDを参照すること' );
		}
	}

	/**
	 * ポストIDが同じでもファイル名が異なる場合・添付ファイル以外の投稿の場合・IDが無い場合は、
	 * 既存メディアを採用せず新規登録することを確認する
	 *
	 * @return void
	 */
	public function test_import_registers_new_attachment_when_existing_media_does_not_match() {
		$existing_id                    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->created_attachment_ids[] = $existing_id;
		$non_attachment_id              = self::factory()->post->create();
		$fixture                        = DIR_TESTDATA . '/images/test-image-3.jpg';

		$test_cases = array(
			array(
				'test_condition_name' => 'ポストIDは既存メディアと同じだがファイル名が異なる場合 => 新規登録',
				'attachment_id'       => $existing_id,
				'original_filename'   => 'test-image-3.jpg',
			),
			array(
				'test_condition_name' => 'ポストIDが添付ファイル以外の投稿を指す場合 => 新規登録',
				'attachment_id'       => $non_attachment_id,
				'original_filename'   => 'test-image-3.jpg',
			),
			array(
				'test_condition_name' => 'ポストIDが無い場合 => 新規登録',
				'attachment_id'       => null,
				'original_filename'   => 'canola.jpg',
			),
		);

		foreach ( $test_cases as $case ) {
			$url    = 'https://staging.example.com/wp-content/uploads/' . $case['original_filename'];
			$markup = '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . esc_url( $url ) . '"/></figure><!-- /wp:image -->';
			$media  = array(
				array(
					'index'             => 0,
					'original_url'      => $url,
					'original_filename' => $case['original_filename'],
					'archive_path'      => 'media/media-0.jpg',
					'attachment_id'     => $case['attachment_id'],
					'size_slug'         => 'full',
					'resolved'          => true,
				),
			);

			$count_before = $this->count_attachments();
			$import       = $this->run_import( $this->build_custom_package( $markup, $media, array( 'media/media-0.jpg' => $fixture ) ) );
			$item         = $import['items'][0];

			$this->assertSame( $count_before + 1, $this->count_attachments(), $case['test_condition_name'] . '(添付ファイルが1件増えること)' );
			$this->assertNotSame( $existing_id, $item['new_attachment_id'], $case['test_condition_name'] . '(既存IDが採用されないこと)' );
			$this->assertFalse( $item['reused'], $case['test_condition_name'] . '(reusedがfalseになること)' );
		}
	}
}
