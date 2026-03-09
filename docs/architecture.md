# 技術仕様書

## テクノロジースタック

### 基盤技術
- **PHP 8.1+**: コア実装言語（XMLReader, cURL, mbstring, JSON処理）
- **a-blog cms v3.2+**: ベースCMS（Logger, Template, ServiceProvider, SQL Builder）
- **Vanilla JavaScript**: 管理画面UI（Fetch API, リアルタイム進捗更新）

### コアアーキテクチャ

#### WordPressブロック変換システム
- **Gutenbergブロック解析**: 正規表現によるブロックコメント抽出
- **JSON属性パース**: ブロック設定の動的解釈
- **a-blog cms形式変換**: メディアID連携によるURL書き換え

#### バッチ処理最適化
- **動的メモリ管理**: 使用量監視による自動調整
- **適応的バッチサイズ**: 処理負荷に応じた柔軟な制御
- **エラー分離**: 個別失敗による全体停止の回避

### 外部依存関係
```json
{
    "require": {
        "php": ">=8.1",
        "ext-xml": "*",
        "ext-xmlreader": "*",
        "ext-curl": "*",
        "ext-mbstring": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "squizlabs/php_codesniffer": "^3.7"
    }
}
```

## 開発環境

### 必須ツール
- PHP 8.1+ ローカル環境
- Composer 2.x

### コード品質
```bash
composer run lint  # PSR12準拠チェック
composer run test  # PHPUnit実行
```

## システム要件

### 動作環境
- **PHP**: 8.1+（xmlreader, curl, mbstring、json拡張）
- **a-blog cms**: 3.2+
- **メモリ**: 最小256MB、推奨512MB以上

### パフォーマンス特性

#### 実装済み最適化機能
- **Generator使用**: 大量データの低メモリ処理（WXRパーサー）
- **バッチ処理**: 動的サイズ調整による効率化
- **URLキャッシュ**: メディア変換の高速化
- **進捗ストリーミング**: Logger活用のリアルタイム更新

#### 処理能力
- **エントリー**: 1,000件/時間（標準設定）
- **メディア**: 500ファイル/時間（ネットワーク依存）
- **メモリピーク**: 通常200MB以下

#### スケーラビリティ
- **最大対応**: 10万エントリー（メモリ管理により）
- **ファイルサイズ**: WXR最大1GB対応
- **同時処理**: 単一セッション設計（ロック機能）
