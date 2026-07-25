<?php
/**
 * ブートストラップが正しく機能するかの疎通確認用テスト
 *
 * @package NExT_Block_Transporter
 */

/**
 * 疎通確認用テストクラス
 */
class Smoke_Test extends WP_UnitTestCase {

	/**
	 * WordPressテスト環境が起動し、プラグインが読み込まれていることを確認する
	 *
	 * @return void
	 */
	public function test_plugin_loaded() {
		$this->assertTrue( class_exists( 'NBT_FS' ), 'NBT_FSクラスが読み込まれていること' );
		$this->assertTrue( class_exists( 'NBT_Import' ), 'NBT_Importクラスが読み込まれていること' );
		$this->assertTrue( class_exists( 'NBT_Export' ), 'NBT_Exportクラスが読み込まれていること' );
	}
}
