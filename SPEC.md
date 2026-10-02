# NExT Block Transporter 仕様書

[日本語](SPEC.md) | [English](SPEC.en.md)

## 1. 背景・目的

Gutenberg編集画面でブロックをコピーし、別サイトの編集画面にペーストすると、
画像ブロックの `src` はコピー元サイトの絶対URLのまま貼り付けられる。
画像の実体(ファイル本体)は一切コピーされないため、ペースト先で画像が表示されない。

Illustratorの「パッケージ」機能(ドキュメントと使用フォント・リンク画像を1フォルダにまとめる)
と同様の発想で、ブロックのマークアップと参照画像の実体をひとつのファイルにまとめ、
サイトを跨いだブロックの移動を可能にする。

## 2. 全体フロー

```
[コピー元サイト]                          [受け入れ側サイト]
編集画面でブロックを選択
   ↓
「エクスポート」実行
   ↓
選択ブロックをserialize()
   ↓
REST /export へPOST
   ↓
サーバー側で画像を検出・収集
   ↓
manifest.json + media/ をZIP化
   ↓
ZIPをダウンロード ------------------→ ZIPファイルをアップロード
                                          ↓
                                     REST /import/start へPOST(ZIP展開・manifest読込)
                                          ↓
                                     REST /import/process-next へ繰り返しPOST
                                     (画像を1件ずつメディアライブラリへ再登録)
                                          ↓
                                     REST /import/finish へPOST
                                     (ブロックマークアップ内のURL/IDを置換・確定)
                                          ↓
                                     wp.blocks.rawHandler()でブロック化
                                          ↓
                                     編集中の投稿へ挿入
```

インポートを3リクエストに分割しているのは、メディア点数・ファイルサイズが大きいパッケージを
1リクエストで一括処理すると、遅い/リソース制限の厳しいサーバーで `max_execution_time` や
`memory_limit` を超えて処理が途中終了してしまうため(詳細は4章)。

## 3. パッケージファイル形式

拡張子は `.zip`(内部的に `.nxbt` を名乗ってもよいが実体はZIP)。

```
package.zip
├── manifest.json
└── media/
    ├── media-0.jpg
    ├── media-1.png
    └── ...
```

- メディア格納フォルダは `media/` とする。現在の検出対象は画像のみだが、
  将来的にPDF・動画・音声などを同梱するため、`images/` ではなく `media/` を採用。

### manifest.json スキーマ

```json
{
  "format_version": "1.0",
  "plugin": "next-block-transporter",
  "plugin_version": "0.2.0",
  "created_at": "2026-07-12T12:00:00+00:00",
  "source_site": "https://source-site.example.com",
  "block_markup": "<!-- wp:image {\"id\":123,...} --><figure>...<img src=\"https://source-site.example.com/wp-content/uploads/2026/07/photo.jpg\" class=\"wp-image-123\"/></figure><!-- /wp:image -->",
  "media": [
    {
      "index": 0,
      "original_url": "https://source-site.example.com/wp-content/uploads/2026/07/photo-1024x683.jpg",
      "original_filename": "photo.jpg",
      "archive_path": "media/media-0.jpg",
      "attachment_id": 123,
      "size_slug": "large",
      "resolved": true
    }
  ]
}
```

- `block_markup`: 選択ブロックを `wp.blocks.serialize()` した生マークアップをそのまま保持する。
  インポート時は `parse_blocks()` でパースして以下を新しい添付ファイルの値へ書き換え、
  `serialize_blocks()` で再構築する(innerBlocks・入れ子属性も再帰的に処理)。
  - ブロック属性(コメントJSON)の `id` / `mediaId` / `ids`
  - HTML内のメディアURLと `wp-image-{id}` クラス
- コメントJSON内のIDまで書き換えるのは、移行先で旧attachment IDが別のメディアに
  使われていた場合に、エディタ操作(画像サイズ変更等)で無関係な画像へ差し替わる事故を防ぐため。
- `wp-image-{id}` の置換は直後に数字が続かない境界チェック付きで行い、旧IDが別IDの
  一部にマッチする誤置換(例: 旧ID `12` が `wp-image-123` にマッチ)を防ぐ。
- `media[]` はマークアップ中のURL参照ごとに1エントリ。エクスポートする実体は、参照が
  サムネイル等の縮小版URLであっても**添付ファイルのオリジナル画像**
  (`wp_get_original_image_path()`、画像以外は `get_attached_file()`)とする。
  同一添付が複数サイズで参照される場合も実体はZIPへ1回だけ格納し、`archive_path` を
  共有する(インポート側もメディア登録を1回に重複排除する)。
- `media[].original_filename`: エクスポート元での実ファイル名。インポート時の登録ファイル名・
  メディアタイトルに使用する(外部入力のため `sanitize_file_name()` を通す)。
  欠落時(旧形式パッケージ)は `archive_path` の連番ファイル名にフォールバックする。
- `media[].size_slug`: `original_url` が指していた登録画像サイズのスラッグ
  (添付メタデータの `sizes[].file` とURLのファイル名を突き合わせて判定。不一致・原寸は `full`)。
  インポート側ではオリジナル画像の登録時に各サイズが再生成されるため、マークアップ中のURLは
  `wp_get_attachment_image_url( 新ID, size_slug )` の結果へ差し替える。
  該当サイズが無い場合・画像以外はフルサイズのURLにフォールバックする。
  ブロック属性の `sizeSlug` はそのまま保持されるため、エディタの「解像度」設定と整合する。
- `media[].resolved`: エクスポート元でファイル実体を解決できなかった場合は `false`。
  その場合はインポート側で元のURLのまま扱う(＝メディアは欠落するがブロック構造は壊さない)。
- `media[].attachment_id`: エクスポート元での添付ファイルID(メディアのポストID)。
  インポート先に**同じIDの添付ファイルが存在し、かつそのオリジナル画像のファイル名が
  `original_filename` と一致する**場合は、新規登録せず既存の添付ファイルを採用する
  (ステージング→本番など同一サイト系統間の移行でメディアが重複登録されるのを防ぐ)。
  IDだけで判定しないのは、元サイトで後から追加したメディアのIDがインポート先では
  無関係な投稿・別の画像に使われている可能性があるため。ファイル名の比較はエクスポート側と
  同じく `wp_get_original_image_path()`(画像以外は `get_attached_file()`)の basename で行う。
  IDが無い・一致しない場合は従来どおり新規登録する。
- 互換性: フォルダ名変更前の開発中パッケージ(`images` キー)は、インポート時に
  フォールバックとして受け付ける。

## 4. REST API

### POST `/wp-json/next-block-transporter/v1/export`

| パラメータ | 型 | 説明 |
|---|---|---|
| block_markup | string | 選択ブロックのシリアライズ済みHTML |

レスポンス:
```json
{ "download_url": "https://.../wp-content/uploads/nbt-tmp/xxx.zip", "filename": "xxx.zip" }
```

### インポートAPI(3リクエストに分割)

メディア点数・ファイルサイズが大きいパッケージを1リクエストで一括処理(ZIP展開 +
全メディアのサイドロード + 画像サイズ再生成)すると、遅い/リソース制限の厳しいサーバーでは
`max_execution_time` や `memory_limit` を超えて処理が途中終了し、レスポンスがJSONにならず
インポートが失敗することがある(ローカル環境では気づきにくい)。これを避けるため、
インポートは「展開」「メディア登録(1件ずつ)」「マークアップ確定」の3リクエストに分割する。
各リクエストは `NBT_FS::raise_processing_limits()` で実行時間・メモリの上限緩和も行う。

セッション状態(展開先パス・処理待ちメディア一覧・書き換えマップ等)は、展開先フォルダ直下の
状態ファイル(`.nbt-import-state.json`、非公開)にサーバー側で保持し、`session_id` で
呼び出しをまたいで引き継ぐ。`session_id` は展開先フォルダ名から推測困難なトークン部分を
抜き出したもの(`NBT_Import::SESSION_ID_PATTERN` で英数字のみを検証)。

#### POST `/wp-json/next-block-transporter/v1/import/start`

`multipart/form-data`、フィールド名 `package`(ZIPファイル)。ZIPを展開しmanifest.jsonを
読み込んでセッションを開始する。メディアの登録はまだ行わない。

レスポンス:
```json
{ "session_id": "xxxxxxxxxxxxxxxxxxxx", "total": 3 }
```

#### POST `/wp-json/next-block-transporter/v1/import/process-next`

セッション内で未処理のメディアを1件だけメディアライブラリへ登録する。呼び出し側
(`assets/js/editor.js`)は `remaining` が `0` になるまで繰り返し呼び出す(`total` が `0` の
場合はメディアが無いので1回も呼ばずfinishへ進む)。

パラメータ: `{ "session_id": "..." }`

レスポンス:
```json
{
  "remaining": 2,
  "item": { "original_url": "...", "new_url": "...", "new_attachment_id": 456, "reused": false }
}
```

`reused` は、新規登録せずインポート先の既存メディアを採用した場合に `true`。

#### POST `/wp-json/next-block-transporter/v1/import/finish`

全メディアの登録完了後(`remaining` が `0` になった後)に呼び出し、書き換え済み
ブロックマークアップを確定して返す。セッションの一時フォルダはここで削除される。
未処理のメディアが残っている状態で呼び出した場合はエラー(`nbt_session_incomplete`)。

パラメータ: `{ "session_id": "..." }`

レスポンス:
```json
{
  "block_markup": "書き換え後のブロックマークアップ",
  "imported_media": [
    { "original_url": "...", "new_url": "...", "new_attachment_id": 456, "reused": false }
  ]
}
```

権限: いずれも `edit_posts` 権限を要求(`current_user_can`)。

## 4.5 セキュリティ方針

パッケージ(ZIP)とmanifestは外部から持ち込まれる**信頼できない入力**として扱う。

- **エクスポート時のファイル解決**: マークアップ中のURLから実ファイルを解決する際、
  `../` 等でuploads外(`wp-config.php` 等)を指せないよう、実パスがuploadsディレクトリ内に
  収まることを検証する(`NBT_FS::within_dir()`)。
- **ZIP展開(zip-slip / zip爆弾対策)**: `extractTo()` で一括展開する前に全エントリを検査し、
  絶対パス・`../`・ドライブレターを含むエントリ名、展開先の外を指すパスを拒否する。
  エントリ数(最大2000)と展開後合計サイズ(最大500MB)にも上限を設ける。
- **manifestの `archive_path`**: 外部入力のため、展開先内に収まることを検証してから読み込む
  (パストラバーサルで任意ファイルをメディアライブラリへコピーさせない)。
- **拡張子偽装対策**: メディア登録時に `wp_check_filetype_and_ext()` でファイルの実内容と
  拡張子の整合を確認し、`get_allowed_mime_types()` で許可された形式のみ受け入れる。
- **一時ファイルの保護**: `uploads/nbt-tmp/` は推測困難なファイル名(20文字トークン)で生成し、
  `index.php` でディレクトリリスティングを無効化、TTL(1時間)超過分をcron・生成時に削除する。
  ※ ダウンロードURLは公開ディレクトリ上のため、URLを知る第三者はTTL内であれば取得可能。
  機密性の高いパッケージを扱う場合は認証付きストリーミング配信への変更を検討する(将来課題)。
- **インポートセッションIDの検証**: `/import/process-next` `/import/finish` が受け取る
  `session_id` は英数字のみ(`NBT_Import::SESSION_ID_PATTERN`)であることを検証したうえで、
  実パスが一時作業フォルダ内に収まることを`NBT_FS::within_dir()`で二重チェックしてから
  展開先フォルダとして扱う(他人の推測困難なセッションへの正規化バイパスを防ぐ)。

## 5. 未対応・今後の課題(TODO)

- [x] gallery / cover / media-text ブロックなど、`background-image` やinnerBlocks内に
      画像が入れ子になっているケースの再帰的な検出
      — エクスポート時に `parse_blocks()` でツリー化し、innerBlocks を再帰探索。
      `<img>` に加え `background-image:url(...)` と cover/media-text 等の
      `url`/`mediaUrl` 属性(メディア本体を持つコアブロックに限定)からも収集。同一URLは重複排除。
- [ ] 動画・音声・PDFなど画像以外のメディアタイプの検出・収集への対応拡張
      (パッケージ形式側は `media/` フォルダ・`media` キーで対応済み)
- [x] 一時ZIP・展開フォルダ(`wp-content/uploads/nbt-tmp/`)の定期クリーンアップ(cron)
      — 日次cron + 生成時に期限切れ(TTL 1時間)を掃除。`index.php` でリスティング無効化。
- [x] 大量画像・大容量ファイルに対するタイムアウト/メモリ対策
      — インポートはメディア1件ずつの登録に分割(`/import/start` → `/import/process-next` ×N →
      `/import/finish`)し、1リクエストが処理する重い処理(サイドロード+画像サイズ再生成)を
      常に1ファイル分に抑えた。エクスポート・インポートとも `NBT_FS::raise_processing_limits()`
      で実行時間・メモリの上限緩和も併用する。
      ※ エクスポート側のZIP圧縮(`ZipArchive::close()`)自体は引き続き1リクエスト内の一括処理
      のため、極端に大量・大容量な画像を選択した場合はエクスポートのストリーミング化が今後の課題として残る。
- [x] 同一サイト内での重複インポート時に既存メディアと重複登録されないようにする
      — 添付ファイルID(ポストID)とオリジナル画像のファイル名が一致する既存メディアを採用する。
      ※ ファイル名を変えずに中身だけ差し替えた画像は既存側が採用される(ハッシュ照合は今後の検討課題)。
- [ ] エクスポート時のUIで「選択ブロックが空」「画像0件」などのエッジケース文言を精査
- [ ] `.nxbt` 独自拡張子でのブランディングを行うかどうかの最終判断

## 6. 命名・ブランド関連メモ

- プラグイン名: **NExT Block Transporter**
- 検討過程で「NExT Block Package」「NExT Block Package Transporter」等も候補に挙がったが、
  スラッグの長さ・意味の重複を避けるため「NExT Block Transporter」に決定。
- プラグインスラッグ: `next-block-transporter`
- 関数・クラスのプレフィックス: `NBT_` / `nbt_`
