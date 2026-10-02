# NExT Block Transporter

[日本語](README.md) | [English](README.en.md)

Gutenbergのブロックを画像込みでパッケージ化し、別サイトへ持ち運べるようにするWordPressプラグイン。

## 概要

Gutenberg編集画面でブロックをコピー&ペーストすると、画像がパスのみ含まれ実体が伴わないため、
別サイトへ貼り付けた際に画像が表示されない問題があります。

本プラグインは、Illustratorの「パッケージ」機能のように、選択したブロックのマークアップと
参照画像の実体をひとつのZIPファイル(`manifest.json` + `media/`)にまとめてエクスポートします。

受け入れ側サイトでは、そのパッケージファイルをアップロードするだけで:

1. 画像をメディアライブラリへ再アップロード
2. ブロックマークアップ内の画像URL・attachment IDを新しいものに修正
3. ブロックを編集中の投稿へ復元

という一連の処理を自動で行います。

## 動作要件

| 項目 | 要件 |
| --- | --- |
| WordPress | 6.4以上(動作確認: 6.7) |
| PHP | 7.4以上 |

## インストール

1. プラグインファイルを `wp-content/plugins/next-block-transporter` に配置
2. 管理画面の「プラグイン」からNExT Block Transporterを有効化
3. 投稿編集画面の右上メニュー、または追加されたサイドバーから利用

## 使い方

### エクスポート(コピー元サイト)

1. 投稿編集画面で持ち運びたいブロックを選択
2. サイドバー「NExT Block Transporter」の「選択ブロックをエクスポート」をクリック
3. パッケージファイル(ZIP)がダウンロードされる

### インポート(受け入れ側サイト)

1. 投稿編集画面でサイドバー「NExT Block Transporter」を開く
2. 「インポート」パネルでパッケージファイル(`.zip` / `.nxbt`)を選択
3. 画像がメディアライブラリへ再登録され、ブロックが編集中の投稿へ挿入される

## 仕様

パッケージ形式・REST API・内部処理の詳細は [SPEC.md](SPEC.md) を参照してください。

## 開発

コーディング規約はWordPress Coding Standardsに準拠しています。
設定は [phpcs.xml.dist](phpcs.xml.dist) を参照してください。

```sh
composer install
composer run phpcs
```

### PHPUnitテストの実行

WordPressコアのテストスイート([wp-phpunit/wp-phpunit](https://github.com/wp-phpunit/wp-phpunit))をComposer経由で利用する構成のため、
別途 wp-env(Docker)や `svn` は不要です。以下だけで動作します。

1. テスト用のMySQLデータベースを用意する(既存のDBとは別の空データベースを1つ作成するだけでよい)。
2. 以下の環境変数でDB接続情報を指定して `vendor/bin/phpunit`(または `composer run phpunit`)を実行する。

```sh
composer install
WP_TESTS_DB_NAME=nbt_phpunit_test \
WP_TESTS_DB_USER=root \
WP_TESTS_DB_PASSWORD=root \
WP_TESTS_DB_HOST=127.0.0.1 \
composer run phpunit
```

| 環境変数 | 説明 | 省略時のデフォルト |
| --- | --- | --- |
| `WP_TESTS_DB_NAME` | テスト用データベース名 | `nbt_phpunit_test` |
| `WP_TESTS_DB_USER` | DBユーザー | `root` |
| `WP_TESTS_DB_PASSWORD` | DBパスワード | `root` |
| `WP_TESTS_DB_HOST` | DBホスト(`host:port` や `host:/path/to/socket.sock` 形式も可) | `127.0.0.1` |
| `WP_TESTS_ABSPATH` | テストで読み込むWordPress本体のパス(ABSPATH) | 本プラグイン自身のパスから相対的に自動算出(`wp-content/plugins/`配下で開発している前提) |

Local(Local by Flywheel)等、既に動くWordPress環境の中でこのプラグインを開発している場合は、
`WP_TESTS_ABSPATH` を指定しなくても自動的にそのWordPress本体を利用します。
CIでは `WP_TESTS_ABSPATH` で別途ダウンロードしたWordPress本体を指定しています([.github/workflows/ci.yml](.github/workflows/ci.yml) 参照)。

## ライセンス

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

## Changelog

### 0.4.0

- [ 機能追加 ] 同一サイト系統間(ステージング→本番など)のインポートで、添付ファイルID・オリジナル画像のファイル名が一致する既存メディアを採用し、メディアライブラリへの重複登録を防止
- [ 機能追加 ] インポート完了メッセージに既存メディアを再利用した件数の内訳を表示

### 0.3.0

- [ 不具合修正 ] 遅い/リソース制限の厳しいサーバーで、メディア点数が多い・容量が大きいパッケージのインポートがタイムアウトして失敗する不具合を修正

### 0.2.0

- [ 機能追加 ] gallery / cover / media-text ブロックの入れ子・背景画像も再帰的に収集
- [ 機能追加 ] 取り込んだメディアに元のファイル名を引き継ぎ
- [ 仕様変更 ] エクスポート対象を縮小版ではなくオリジナル画像に変更し、解像度はブロックの設定で指定
- [ 不具合修正 ] 選択ブロックをエクスポートできない不具合を修正
- [ セキュリティ修正 ] パストラバーサル・zip-slip・拡張子偽装への対策と一時ファイルの保護を追加
- [ その他 ] プラグイン削除時に一時ファイル・cron を除去するアンインストール処理を追加

### 0.1.0

- 初期スケルトン作成
