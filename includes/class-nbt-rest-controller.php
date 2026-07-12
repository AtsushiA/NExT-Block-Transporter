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

		register_rest_route(
			NBT_REST_NAMESPACE,
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'permission_check' ),
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
	 * POST /import
	 * multipart/form-data: package (ファイル)
	 * ZIPを受け取り展開・メディア登録・ブロックマークアップ復元を行う。
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト。
	 * @return WP_REST_Response レスポンス
	 */
	public function handle_import( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['package'] ) || UPLOAD_ERR_OK !== $files['package']['error'] ) {
			return new WP_REST_Response( array( 'message' => __( 'ファイルのアップロードに失敗しました。', 'next-block-transporter' ) ), 400 );
		}

		$importer = new NBT_Import();
		$result   = $importer->process_package( $files['package']['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response(
			array(
				'block_markup'   => $result['block_markup'],
				'imported_media' => $result['imported_media'],
			),
			200
		);
	}
}
