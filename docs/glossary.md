# ユビキタス言語定義

WordPress移行プラグイン開発で使用される基本用語を定義します。

## 基本用語

### WordPress用語

#### WXR (WordPress Extended RSS)
WordPress標準エクスポート形式のXMLファイル。投稿、ページ、メディア情報を含む。

#### エントリー (Entry)
ブログ記事。WordPressの投稿や固定ページに相当。

#### メディア (Media)
WordPressの画像、PDF等のアップロードファイル。

#### カテゴリー (Category)
記事の分類システム。

### a-blog cms用語

#### エントリー (Entry)
a-blog cmsの個別記事データ。

#### カテゴリー (Category)
a-blog cmsの記事分類システム。階層構造をサポート。

#### メディア (Media)
a-blog cmsで管理される画像・ファイル群。

### 移行用語

#### インポート (Import)
WordPressデータをa-blog cmsに取り込む処理。

#### セッション (Session)
一連の移行処理の単位。WXRアップロードから完了まで。

#### バッチ処理 (Batch Processing)
大量データを一定単位に分割して順次処理する手法。

## 技術用語

#### Parser (パーサー)
WXRファイルを解析してデータを抽出するクラス。

#### Importer (インポーター)
エントリーやメディアをa-blog cmsに移行するクラス。

#### ContentProcessor (コンテンツプロセッサー)
WordPressブロックをa-blog cmsブロック形式に変換し、URL書き換えを行うクラス。

#### BatchProcessor (バッチプロセッサー)
大量データを効率的に処理する統合処理エンジン。動的最適化機能を含む。

#### ProgressLogger (進捗ログシステム)
a-blog cms標準のLoggerサービスを利用したリアルタイム進捗管理。

## WordPressブロック用語

#### Gutenbergブロック
WordPress 5.0以降のブロックエディターで使用される構造化コンテンツ。

#### ブロックコメント
WordPressブロックを定義するHTMLコメント（例：`<!-- wp:image -->`）。

#### ブロック属性
ブロックの設定情報（JSON形式）。配置、サイズ、スタイル等を含む。

## a-blog cmsブロック用語

#### imageBlock
a-blog cmsの画像表示ブロック。data-type="imageBlock"で定義。

#### fileBlock
a-blog cmsのファイルダウンロードブロック。data-type="fileBlock"で定義。

#### メディアID連携
a-blog cmsメディアマネージャーとの連携機能。data-midによる関連付け。

## 処理最適化用語

#### 動的バッチサイズ
メモリ使用量に応じて自動調整されるバッチ処理単位。

#### URLキャッシュ
メディアURL変換結果の高速化キャッシュシステム。

#### エラー分離
個別アイテムの処理失敗が全体を停止させない仕組み。

## 画面用語

#### アップロード画面
WXRファイルをアップロードする管理画面。

#### 進捗画面
移行処理の進捗をリアルタイムで表示する画面。

#### レポート画面
移行結果の詳細を表示する画面。

## 処理状態

#### pending (待機中)
処理開始前の状態。

#### processing (処理中)
移行処理実行中の状態。

#### completed (完了)
処理が正常完了した状態。

#### error (エラー)
処理中にエラーが発生した状態。





## 命名規則

### PHPクラス
- **パスカルケース**: `EntryImporter`, `WXRParser`
- **役割明確**: `{機能名}{役割}`パターン

### メソッド
- **キャメルケース**: `parse()`, `import()`, `getProgress()`
- **動詞で開始**: 処理実行メソッド

### 変数
- **キャメルケース**: `sessionId`, `entryData`
- **意図明確**: 略語避ける

MVP実装に必要な基本用語のみを定義し、シンプルな開発を行います。