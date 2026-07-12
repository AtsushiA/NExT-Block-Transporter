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
	 */
	async function importPackage( file, setStatus, insertBlocks ) {
		setStatus( { type: 'info', message: __( 'インポート中...', 'next-block-transporter' ) } );

		const formData = new FormData();
		formData.append( 'package', file );

		try {
			const response = await apiFetch( {
				path: '/next-block-transporter/v1/import',
				method: 'POST',
				body: formData,
			} );

			const blocks = rawHandler( { HTML: response.block_markup } );
			insertBlocks( blocks );

			setStatus( {
				type: 'success',
				message: __( 'ブロックを復元しました。(メディア: ', 'next-block-transporter' ) + response.imported_media.length + '件)',
			} );
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
