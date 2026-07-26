<?php
/**
 * NBT_FS のテスト
 *
 * @package NExT_Block_Transporter
 */

/**
 * NBT_FS::raise_processing_limits() / touch_dir() / within_dir() のテストクラス
 */
class NBT_FS_Test extends WP_UnitTestCase {

	/**
	 * テストで作成した一時ディレクトリ(tearDownで削除する)
	 *
	 * @var string[]
	 */
	private $tmp_dirs = array();

	/**
	 * 各テスト後に作成した一時ディレクトリを削除する
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->tmp_dirs as $dir ) {
			NBT_FS::delete_dir( $dir );
		}
		$this->tmp_dirs = array();

		parent::tear_down();
	}

	/**
	 * "256M" のような ini 表記のメモリサイズをバイト数へ変換する(比較用のテストヘルパー)
	 *
	 * @param string $value ini_get('memory_limit') 等が返す文字列。
	 * @return int|float バイト数。無制限("-1")の場合はPHP_INT_MAXを返す
	 */
	private function memory_limit_to_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '-1' === $value || '' === $value ) {
			return PHP_INT_MAX;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;

		switch ( $unit ) {
			case 'g':
				return $number * 1024 * 1024 * 1024;
			case 'm':
				return $number * 1024 * 1024;
			case 'k':
				return $number * 1024;
			default:
				return $number;
		}
	}

	/**
	 * NBT_FS::raise_processing_limits() が例外を出さずに実行でき、メモリ上限を引き下げないことを確認する
	 *
	 * @return void
	 */
	public function test_raise_processing_limits() {
		$before_bytes = $this->memory_limit_to_bytes( ini_get( 'memory_limit' ) );

		// disable_functionsでset_time_limitが禁止されている環境でもエラーにならないことも含めて確認する。
		NBT_FS::raise_processing_limits();

		$after_bytes = $this->memory_limit_to_bytes( ini_get( 'memory_limit' ) );

		$this->assertGreaterThanOrEqual(
			$before_bytes,
			$after_bytes,
			'raise_processing_limits() の呼び出し前よりメモリ上限が引き下げられていないこと'
		);
	}

	/**
	 * NBT_FS::touch_dir() がディレクトリのmtimeを更新し、存在しないパスに対しては何もしないことを確認する
	 *
	 * @return void
	 */
	public function test_touch_dir() {
		$dir = trailingslashit( get_temp_dir() ) . 'nbt-touch-dir-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$this->tmp_dirs[] = $dir;

		// mtimeの変化を確実に検知できるよう、過去の時刻に一度巻き戻しておく。
		$past = time() - 3600;
		touch( $dir, $past ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- テストで意図的に過去のmtimeを設定するため。
		clearstatcache();
		$this->assertSame( $past, filemtime( $dir ), '前提: mtimeを過去の時刻に設定できていること' );

		NBT_FS::touch_dir( $dir );
		clearstatcache();

		$this->assertGreaterThan( $past, filemtime( $dir ), '既存ディレクトリのmtimeが現在時刻へ更新されること' );

		// 存在しないパスを渡してもエラー・例外にならないことを確認する。
		$missing = trailingslashit( get_temp_dir() ) . 'nbt-touch-dir-missing-' . wp_generate_password( 8, false );
		NBT_FS::touch_dir( $missing );
		$this->assertDirectoryDoesNotExist( $missing, '存在しないパスに対しては何も作成されないこと' );
	}

	/**
	 * NBT_FS::within_dir() が基準ディレクトリ内のパスのみを受け入れ、パストラバーサルを拒否することを確認する
	 *
	 * @return void
	 */
	public function test_within_dir() {
		$base = trailingslashit( get_temp_dir() ) . 'nbt-within-dir-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $base );
		$this->tmp_dirs[] = $base;

		// get_temp_dir()はmacOSでは /var -> /private/var のようなシンボリックリンクを経由することがあり、
		// 実在しないネストしたパスの正規化結果と realpath() 済みパスとで基準がずれてしまう。
		// テストの前提を安定させるため、以降は realpath 済みのパスを基準として扱う。
		$base = realpath( $base );

		$test_cases = array(
			array(
				'test_condition_name' => '基準ディレクトリ直下の実在しないファイルパス(展開前提)の場合 => 実パスを返す',
				'path'                => $base . '/nested/file.txt',
				'expect_within'       => true,
			),
			array(
				'test_condition_name' => '基準ディレクトリ自身を指す場合 => 実パスを返す',
				'path'                => $base,
				'expect_within'       => true,
			),
			array(
				'test_condition_name' => '`../` で基準ディレクトリの外(親)を指す場合 => falseを返す',
				'path'                => $base . '/../outside.txt',
				'expect_within'       => false,
			),
			array(
				'test_condition_name' => '`../../../` で大きく上位を指す場合 => falseを返す',
				'path'                => $base . '/../../../etc/passwd',
				'expect_within'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$result = NBT_FS::within_dir( $case['path'], $base );

			if ( $case['expect_within'] ) {
				$this->assertNotFalse( $result, $case['test_condition_name'] );
				$this->assertStringStartsWith( trailingslashit( realpath( $base ) ), trailingslashit( $result ), $case['test_condition_name'] );
			} else {
				$this->assertFalse( $result, $case['test_condition_name'] );
			}
		}
	}
}
