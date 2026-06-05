# ACF JSON CSV Integrator

An integrated tool to export and import ACF (Advanced Custom Fields) flexible content and repeaters seamlessly as JSON-formatted strings via CSV.

## Changelog

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
