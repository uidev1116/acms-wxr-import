# 要求内容: WXRインポート進捗UIから Underscore.js 依存を除去

## 背景

a-blog cms 管理画面の WordPress インポート機能 (`src/template/admin/main.html`) では、進捗UIの描画に `window._.template()` (Underscore.js) を利用している。

a-blog cms コアの最近のバージョン更新で Underscore.js がコア同梱から外れたため、このコードは現状のままでは動作しない可能性がある。グローバル `_` への依存を取り除き、コア配布物に追加依存を持ち込まずに進捗UIを再実装する必要がある。

## ユーザーストーリー

- **As a** 管理画面ユーザー（管理者）
- **I want to** WordPressインポート実行中、進捗状況とメッセージリストをリアルタイムに確認したい
- **So that** インポート処理が正常に進行しているか、エラーが発生していないかを把握できる

## スコープ

### 対象

- `plugins/WXRImport/src/template/admin/main.html` 内の進捗UIブロック (37〜165 行付近)
  - `<script type="text/template" class="js-processing-template">` のテンプレート定義
  - `(function() { ... })()` のインラインスクリプト内、`window._.template()` を使用している処理

### 対象外

- 進捗データを返すバックエンド側 (`POST/WxrImport/ProgressJson` 等) のロジック
- インポート実行ロジック本体
- `topicpath.html` などその他の管理画面テンプレート
- 進捗UI 以外の管理画面UI（インポート前のフォームなど）

## 受け入れ条件

1. **依存除去**: `window._` (Underscore.js) への参照がコードベースから消えていること
2. **新規依存ゼロ**: 新しい npm 依存パッケージ・新しいビルドパイプラインを追加しないこと
3. **機能維持（プログレスバー）**: インポート実行中、プログレスバーのパーセンテージ・色（info/danger）・メッセージが従来同様に更新されること
4. **機能維持（メッセージリスト）**: `processList` の各要素が `<ul><li>` として描画され、`status === 'ng'` の項目は `acms-admin-text-danger` クラス付きで `[Error] {message}` 形式表示になること
5. **機能維持（完了/エラー表示）**: `!processing && success` で完了アラート、`!processing && error` で失敗アラートが従来同様に表示されること
6. **機能維持（タイムアウト）**: `updatedAt` から 180 秒経過時のタイムアウトメッセージ表示が従来同様に動作すること
7. **機能維持（ポーリング停止）**: `processing === false` または fetch エラー時に `setInterval` がクリアされること
8. **XSS 安全性**: サーバから受け取る `message` / `error` / `inProgress` などの文字列値を DOM へ挿入する際、HTML を解釈させないこと（`innerHTML` で直接埋め込まない）
9. **ブラウザ互換**: a-blog cms 管理画面が公式にサポートする最新のモダンブラウザ（Chrome / Edge / Firefox / Safari の現行メジャーバージョン）で動作すること（`<template>` 要素 / `replaceChildren` / `fetch` / `async-await` 利用可）

## 制約事項

- フロントのビルドパイプライン（Vite/esbuild 等）は本プラグインに導入しない方針。既存どおり `main.html` 内の `<script>` 直書きで完結させる。
- React / Vue / Web Components ライブラリ等の追加導入はしない（純粋な DOM API のみ）。
- 既存の CSS クラス名 (`acms-admin-progress`, `acms-admin-progress-bar`, `acms-admin-text-danger`, `acms-admin-alert*` 等) は引き続き同じ DOM 構造で出力する。バックエンド/CMS側の他のスタイル前提を壊さないため。
- `js-background-wxr-import` / `js-progress` / `js-processing-template` / `js-processing-box` といった既存のセレクタ命名規則は踏襲する（必要に応じて追加・変更可だが、テンプレートとロジックの責務分離が明確になる範囲に留める）。

## 非機能要件

- ポーリング間隔: 既存の 2000ms（`initProgressCheck` の第二引数）を維持。
- パフォーマンス: 現状以上の DOM 再生成コストを発生させないこと（メッセージ件数が増えても 1 ポーリングあたり全件再描画で問題なし。差分パッチは不要）。
