# Baseline 計測（改修前）

実測値はステージング環境で取得してこのファイルに追記する。実装着手前に取得することが望ましいが、本ステアリングでは計測フック（Task 13）導入後の after 値との差分で AC 達成判定する運用とする。

## 計測手順

1. 改修前のコミット（本ステアリング着手前 = `87b151a`）をチェックアウト
2. `memory_limit=512M`, `time_limit=0` 環境を確保
3. 下記 WXR を順番に取り込み、`memory_get_peak_usage(true)` と完了までの実時間、`SHOW SESSION STATUS LIKE 'Com_select'/'Com_insert'/'Com_update'` 等で発行クエリ概数を記録

## 計測対象 WXR

| サイズ | エントリー数 | メディア数 | 各メディア平均サイズ | 想定 WXR サイズ |
|--------|--------------|------------|----------------------|------------------|
| S      | 50           | 20         | 200KB                | 約 2MB           |
| M      | 500          | 200        | 500KB                | 約 20MB          |
| L      | 5000         | 1000       | 1MB                  | 約 100MB         |

S は既存リポジトリにテスト用 WXR があれば流用、無ければ手動エクスポートしたものを使う。M / L は S から合成（item ブロックを ID 付け替えで複製）。

## 改修前計測結果（記入欄）

### S サイズ

- 完了時間: _____ 秒
- ピークメモリ: _____ MB
- SELECT 数: _____
- INSERT 数: _____
- UPDATE 数: _____
- `category` テーブル UPDATE 数: _____
- `media` テーブル SELECT 数: _____

### M サイズ

- 完了時間: _____ 秒
- ピークメモリ: _____ MB
- SELECT 数: _____
- INSERT 数: _____
- UPDATE 数: _____
- `category` テーブル UPDATE 数: _____
- `media` テーブル SELECT 数: _____

### L サイズ

- 完了時間: _____ 秒 / もしくは OOM 発生
- ピークメモリ: _____ MB / もしくは OOM
- 備考: _____

## メモ

- 計測は同一 DB スキーマ・同一データセットで行う（前回取り込み結果を `DELETE FROM entry WHERE ...` 等で除いてから再取り込み）
- DB クエリ概数は MariaDB の `SHOW STATUS` カウンタ差分で取得
- 実行時間は `Execute.php` の `executeImportProcess` 開始〜 `logger->success()` までを `microtime(true)` で計測
