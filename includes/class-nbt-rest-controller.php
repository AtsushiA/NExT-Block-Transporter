<?php
/**
 * REST APIエンドポイント定義
 *
 * @package NExT_Block_Transporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST APIエンドポイントを提供するクラス
 */
class NBT_Rest_Controller {

	/**
	 * REST APIルートを登録する
	 */
	public function register_routes() {
		register_rest_route(
			NBT_REST_NAMESPACE,
			'/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_export' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'block_markup' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// インポートは「ZIP展開(start)」→「メディア1件ずつ登録(process-next)」→
		// 「マークアップ確定(finish)」の3リクエストに分割する。1リクエストで全処理を
		// 行うと、メディア点数・ファイルサイズによっては遅い/リソース制限の厳しい
		// サーバーで max_execution_time や memory_limit を超えて失敗するため。
		register_rest_route(
			NBT_REST_NAMESPACE,
			'/import/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import_start' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			NBT_REST_NAMESPACE,
			'/import/process-next',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import_process_next' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'session_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			NBT_REST_NAMESPACE,
			'/import/finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import_finish' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'session_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * 編集権限(投稿編集者以上)を要求する
	 *
	 * @return bool 権限があれば true
	 */
	public function permission_check() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * POST /export
	 * body: { block_markup: string }
	 * 選択中ブロックのシリアライズ済みマークアップを受け取りZIPを生成、
	 * ダウンロードURLを返す。
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト。
	 * @return WP_REST_Response レスポンス
	 */
	public function handle_export( WP_REST_Request $request ) {
		$block_markup = $request->get_param( 'block_markup' );

		$exporter = new NBT_Export();
		$result   = $exporter->build_package( $block_markup );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response(
			array(
				'download_url' => $result['zip_url'],
				'filename'     => $result['filename'],
			),
			200
		);
	}

	/**
	 * POST /import/start
	 * multipart/form-data: package (ファイル)
	 * ZIPを展開しmanifest.jsonを読み込んでインポートセッションを開始する。
	 * メディアの登録はまだ行わない(/import/process-nextで1件ずつ行う)。
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト。
	 * @return WP_REST_Response レスポンス
	 */
	public function handle_import_start( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['package'] ) || UPLOAD_ERR_OK !== $files['package']['error'] ) {
			return new WP_REST_Response( array( 'message' => __( 'ファイルのアップロードに失敗しました。', 'next-block-transporter' ) ), 400 );
		}

		$importer = new NBT_Import();
		$result   = $importer->start_session( $files['package']['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $this->error_status( $result ) );
		}

		return new WP_REST_Response(
			array(
				'session_id' => $result['session_id'],
				'total'      => $result['total'],
			),
			200
		);
	}

	/**
	 * POST /import/process-next
	 * body: { session_id: string }
	 * セッション内で未処理のメディアを1件だけ登録する。remainingが0になるまで
	 * 呼び出し側が繰り返し呼ぶことを想定している。
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト。
	 * @return WP_REST_Response レスポンス
	 */
	public function handle_import_process_next( WP_REST_Request $request ) {
		$importer = new NBT_Import();
		$result   = $importer->process_next( $request->get_param( 'session_id' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $this->error_status( $result ) );
		}

		return new WP_REST_Response(
			array(
				'remaining' => $result['remaining'],
				'item'      => $result['item'],
			),
			200
		);
	}

	/**
	 * POST /import/finish
	 * body: { session_id: string }
	 * 全メディアの登録完了後に、書き換え済みブロックマークアップを確定して返す。
	 * セッションの一時フォルダはここで削除される。
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト。
	 * @return WP_REST_Response レスポンス
	 */
	public function handle_import_finish( WP_REST_Request $request ) {
		$importer = new NBT_Import();
		$result   = $importer->finish_session( $request->get_param( 'session_id' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $this->error_status( $result ) );
		}

		return new WP_REST_Response(
			array(
				'block_markup'   => $result['block_markup'],
				'imported_media' => $result['imported_media'],
			),
			200
		);
	}

	/**
	 * WP_Errorのエラーコードに応じたHTTPステータスコードを返す
	 *
	 * @param WP_Error $error 判定対象のエラー。
	 * @return int HTTPステータスコード
	 */
	private function error_status( WP_Error $error ) {
		$not_found_codes   = array( 'nbt_session_not_found' );
		$bad_request_codes = array( 'nbt_invalid_session', 'nbt_session_invalid', 'nbt_session_incomplete' );

		if ( in_array( $error->get_error_code(), $not_found_codes, true ) ) {
			return 404;
		}
		if ( in_array( $error->get_error_code(), $bad_request_codes, true ) ) {
			return 400;
		}
		return 500;
	}
}
