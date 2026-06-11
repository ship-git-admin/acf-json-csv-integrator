# ACF JSON CSV Integrator

An integrated tool to export and import ACF (Advanced Custom Fields) flexible content and repeaters seamlessly as JSON-formatted strings via CSV.

## Changelog

### 1.0.14 (2026-06-11)
- キャッシュ回避のためのバージョンアップ
- キャッシュを強制バイパスするクエリパラメータ `?force_update_check=1` ハンドラーの実装。

### 1.0.13 (2026-06-11)
- 新機能: オプションページデータ（`options_page_id`を含むCSV）のインポート処理を実装。
- 不具合修正: オプションページインポート時に `post_type` 不足の警告文（HTML）がAjaxレスポンスのJSONの前に出力されてしまい、フロントエンド側で `SyntaxError: Unexpected token '<'` が発生する問題を解消。

### 1.0.12 (2026-06-10)
- 不具合修正: Ajaxハンドラー内でヘッダー行を解析する際、`$importer->parse_columns` を誤って呼び出していた記述を `$h->parse_columns` （正しくはヘルパークラスのメソッド）へ修正。

### 1.0.11 (2026-06-10)
- 不具合修正: Ajaxバッチ処理中に `wp_create_categories` などの管理画面用関数が未定義となり 500エラーになる問題への対策として、必要なコアファイル (`taxonomy.php`, `image.php`, `file.php`, `media.php`) を明示的に読み込むよう修正。
- 改善: Ajax処理中にFatal Errorが発生した場合、500エラーではなくエラー内容をJSON（アラート表示）で返すようにエラーハンドリングを追加。

### 1.0.10 (2026-06-10)
- 不具合修正: Ajaxリクエスト時に `WP_LOAD_IMPORTERS` が未定義のためインポーターモジュールが読み込まれず、バッチ処理で 400 Bad Request が発生する問題を修正。

### 1.0.9 (2026-06-08)
- インポート処理をAjaxによるバッチ処理に変更。大容量CSVインポート時のタイムアウトおよびメモリエラーを解消。

### 1.0.8 (2026-06-05)
- 不具合修正: BOM付き＋全項目クォートのCSVで1列目のカラム名が壊れ（例: `"post_id"`）、post_id等が認識されず更新できない問題を修正（BOM除去後に残る囲みクォートを除去）。

### 1.0.7 (2026-06-05)
- タームインポートで `parent` 列をスラッグ（または名前）で指定できるように対応。親を先頭に並べた1ファイルで親子階層を構築可能（親IDが不要に）。

### 1.0.6 (2026-05-29)
- タクソノミーターム（分類情報）のインポートおよびエクスポート機能を追加
- 画像ダウンロード時にBasic認証を突破する機能（管理画面に入力フォームを追加）を実装
- その他、インポート処理の改善とバグ修正

### 1.0.2 (2026-05-19)
- 自動アップデート認証用のトークンを制限付きトークン（Fine-grained PAT）へ移行

### 1.0.1 (2026-05-19)
- 不具合の修正 (Bug fixes and stability improvements)

### 1.0.0 (2026-05-19)
- 初回リリース (Initial release)
