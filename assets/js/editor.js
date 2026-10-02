/**
 * NExT Block Transporter - エディタ統合スクリプト
 *
 * ビルドステップ不要(wp.element.createElementを直接使用)。
 * TODO: 複雑化してきたら @wordpress/scripts + JSX へ移行を検討。
 */
( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
	const { PanelBody, Button, Notice } = wp.components;
	const { createElement: el, useState } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { serialize, rawHandler } = wp.blocks;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;

	/**
	 * 選択中ブロックをエクスポートする
	 */
	async function exportSelectedBlocks( setStatus ) {
		// core/block-editor ストアに getSelectedBlocks というセレクターは存在しないため、
		// 単一選択・複数選択の両方をカバーできる getSelectedBlockClientIds 経由で取得する。
		const blockEditor = wp.data.select( 'core/block-editor' );
		const clientIds = blockEditor.getSelectedBlockClientIds();

		if ( ! clientIds || clientIds.length === 0 ) {
			setStatus( { type: 'error', message: __( 'エクスポートするブロックを選択してください。', 'next-block-transporter' ) } );
			return;
		}

		const selectedBlocks = blockEditor.getBlocksByClientId( clientIds ).filter( Boolean );

		setStatus( { type: 'info', message: __( 'パッケージを作成中...', 'next-block-transporter' ) } );

		const blockMarkup = serialize( selectedBlocks );

		try {
			const response = await apiFetch( {
				path: '/next-block-transporter/v1/export',
				method: 'POST',
				data: { block_markup: blockMarkup },
			} );

			// ダウンロードをトリガー
			const link = document.createElement( 'a' );
			link.href = response.download_url;
			link.download = response.filename;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );

			setStatus( { type: 'success', message: __( 'パッケージをダウンロードしました。', 'next-block-transporter' ) } );
		} catch ( error ) {
			setStatus( { type: 'error', message: error.message || __( 'エクスポートに失敗しました。', 'next-block-transporter' ) } );
		}
	}

	/**
	 * パッケージファイルをインポートし、現在のエディタにブロックを挿入する
	 *
	 * メディア点数・ファイルサイズが大きいパッケージを1リクエストで一括処理すると、
	 * 遅い/リソース制限の厳しいサーバーではサーバー側のタイムアウトで失敗することがある。
	 * これを避けるため、サーバー側と同様に「展開(start)」→「メディアを1件ずつ登録
	 * (process-next)」→「マークアップ確定(finish)」の複数リクエストに分割して呼び出す。
	 */
	async function importPackage( file, setStatus, insertBlocks ) {
		setStatus( { type: 'info', message: __( 'パッケージを展開中...', 'next-block-transporter' ) } );

		const formData = new FormData();
		formData.append( 'package', file );

		try {
			const { session_id: sessionId, total } = await apiFetch( {
				path: '/next-block-transporter/v1/import/start',
				method: 'POST',
				body: formData,
			} );

			// メディアが0件でもtotalは0なのでループはスキップされ、そのままfinishへ進む。
			for ( let done = 0; done < total; done++ ) {
				setStatus( {
					type: 'info',
					message: __( 'メディアを登録中...', 'next-block-transporter' ) + ' (' + ( done + 1 ) + '/' + total + ')',
				} );

				// 直前の結果が次件の処理に影響しないよう、1件ずつ完了を待ってから次を呼ぶ。
				await apiFetch( { // eslint-disable-line no-await-in-loop
					path: '/next-block-transporter/v1/import/process-next',
					method: 'POST',
					data: { session_id: sessionId },
				} );
			}

			setStatus( { type: 'info', message: __( 'ブロックを復元中...', 'next-block-transporter' ) } );

			const response = await apiFetch( {
				path: '/next-block-transporter/v1/import/finish',
				method: 'POST',
				data: { session_id: sessionId },
			} );

			const blocks = rawHandler( { HTML: response.block_markup } );
			insertBlocks( blocks );

			// 既存メディアを採用した件数(同一サイト間の移行で重複登録を避けたもの)を内訳として表示する。
			const reusedCount = response.imported_media.filter( ( item ) => item.reused ).length;
			let message = __( 'ブロックを復元しました。(メディア: ', 'next-block-transporter' ) + response.imported_media.length + '件';
			if ( reusedCount > 0 ) {
				message += __( '、うち既存メディアを利用: ', 'next-block-transporter' ) + reusedCount + '件';
			}
			message += ')';

			setStatus( { type: 'success', message } );
		} catch ( error ) {
			setStatus( { type: 'error', message: error.message || __( 'インポートに失敗しました。', 'next-block-transporter' ) } );
		}
	}

	function TransporterSidebar() {
		const [ status, setStatus ] = useState( null );
		const { insertBlocks } = useDispatch( 'core/block-editor' );

		return el(
			wp.element.Fragment,
			{},
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'nbt-sidebar' },
				__( 'NExT Block Transporter', 'next-block-transporter' )
			),
			el(
				PluginSidebar,
				{ name: 'nbt-sidebar', title: __( 'NExT Block Transporter', 'next-block-transporter' ) },
				el(
					PanelBody,
					{ title: __( 'エクスポート', 'next-block-transporter' ) },
					el(
						'p',
						{},
						__( '編集画面で選択中のブロックを画像込みでパッケージ化します。', 'next-block-transporter' )
					),
					el(
						Button,
						{ variant: 'primary', onClick: () => exportSelectedBlocks( setStatus ) },
						__( '選択ブロックをエクスポート', 'next-block-transporter' )
					)
				),
				el(
					PanelBody,
					{ title: __( 'インポート', 'next-block-transporter' ), initialOpen: true },
					el(
						'p',
						{},
						__( 'パッケージファイル(.zip / .nxbt)を選択すると、画像を再アップロードしてブロックを復元します。', 'next-block-transporter' )
					),
					el( 'input', {
						type: 'file',
						accept: '.zip,.nxbt',
						onChange: ( event ) => {
							const file = event.target.files[ 0 ];
							if ( file ) {
								importPackage( file, setStatus, insertBlocks );
							}
						},
					} )
				),
				status &&
					el(
						Notice,
						{ status: status.type === 'error' ? 'error' : status.type === 'success' ? 'success' : 'info', isDismissible: false },
						status.message
					)
			)
		);
	}

	registerPlugin( 'next-block-transporter', {
		render: TransporterSidebar,
	} );
} )( window.wp );
