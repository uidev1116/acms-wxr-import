# タスクリスト: パフォーマンス改善実装

## 実行順序の方針

- **「小さく独立／低リスクなものから先行」**。最終3タスク（P-1 / P-3 / P-8）が最も影響範囲が広いため、それまでに周辺整理を完了させてから着手する。
- **1 タスク = 1 コミット** を原則とする。同一ファイルに複数タスクが当たる場合は順序で衝突回避（補足参照）。
- 計測タスク（Task 12）は最後に置き、改善効果を確定させる。
- 各コミット前に `php -l` を必ず実行する（既存ステアリングの運用に倣う）。

---

## Task 0: 1次ソース確認とテスト用 WXR の準備

**実装変更なし。事前準備のみ**:

- [ ] `ablogcms/php/Services/Storage/Filesystem.php:784-787` の `removeIllegalCharacters` 実装を再確認（チャンク境界安全性の最終確認）
- [ ] `ablogcms/php/Services/Entry/Helper.php:517-548` の `SQL::newBulkInsert()` 使用パターンを再確認
- [ ] `ablogcms/php/Services/Entry/EntryRepository.php` の `nextSort` / `nextUserSort` / `nextCategorySort` の実装を確認（`SortValueAllocator` の挙動と整合させるため）
- [ ] 計測用 WXR の準備:
  - **S**: 50エントリ / 20メディア（既存の動作確認用、リポジトリにあれば流用）
  - **M**: 500エントリ / 200メディア
  - **L**: 5000エントリ / 1000メディア（合成、各画像 1MB 程度）
- [ ] 現状ブランチで S / M / L の **before 計測値** を取得（処理時間・ピークメモリ・概算クエリ数）し、`.steering/20260518-improve-performance/baseline.md` に記録

**完了条件**: baseline.md が作成され、改修後の比較材料になっている。

---

## Task 1: `MemoryLimit` ヘルパー作成と `BatchProcessor::$memoryLimit` 修正 (P-2)

**新規ファイル**: `src/Services/Helpers/MemoryLimit.php`

- [ ] クラス `MemoryLimit` を作成し、`public static function inBytes(): int` を実装:
      ```php
      $raw = ini_get('memory_limit');
      if ($raw === false || $raw === '-1') { return PHP_INT_MAX; }
      $value = (int) $raw;
      return match (strtolower(substr($raw, -1))) {
          'g' => $value * 1024 * 1024 * 1024,
          'm' => $value * 1024 * 1024,
          'k' => $value * 1024,
          default => $value,
      };
      ```

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] `use Acms\Plugins\WxrImport\Services\Helpers\MemoryLimit;` を追加
- [ ] L55 を `$this->memoryLimit = MemoryLimit::inBytes();` に変更
- [ ] `optimizeBatchSize()` (L477-498) を修正:
  - 「メモリ余裕時に1.5倍に増加させる」ロジックを削除（管理画面値を尊重）
  - 「残メモリ20%未満で半減」のみ残す
  - 上限は `MAX_BATCH_SIZE` ではなく `$requestedSize` とする
- [ ] `php -l src/Services/Helpers/MemoryLimit.php src/Services/Import/BatchProcessor.php`
- [ ] コミット: `Fix BatchProcessor memory budget to use ini memory_limit instead of current usage`

**完了条件**: `memory_limit=512M` 環境で `batch_size=50` を指定したとき、`optimizeBatchSize(50, 1000)` が 50 を返す。

---

## Task 2: 不要 `usleep` / `sleep` の整理 (P-11)

**ファイル**: `src/POST/WxrImport/Execute.php`

- [ ] L201 の `sleep(5);` を削除（または `sleep(1)` 程度に縮小）

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] L294 のバッチ間 `usleep(100000)` を **`config('wxr_import_batch_pause_microseconds')` 由来の値（デフォルト 0）** で置き換える
- [ ] L395 メディア件間 `usleep(100000)` を削除（`Downloader::applyRateLimit()` が HTTP 経路で実施済み）
- [ ] L409 メディアバッチ間 `usleep(200000)` を削除

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `applyRateLimit()` が **HTTP 経路でのみ呼ばれている** ことを確認（既存挙動）。コードの追加変更は不要。

- [ ] `php -l` で関連ファイルの構文チェック
- [ ] コミット: `Remove redundant sleeps and make batch pause configurable`

**完了条件**: ローカルパス取り込み経路で固定 `usleep` が発生しない。HTTP 経路では `applyRateLimit()` のみ作用する。

---

## Task 3: Parser の細部最適化（XPath 相対化 + dedup 高速化） (P-9, P-13)

**ファイル**: `src/Services/WXR/Parser.php`

- [ ] `extractItem()` (L205-265) の `getXPathValue($xpath, '//...')` をすべて `'./...'` または `'.//'` に変更
- [ ] `extractPostMeta()` (L519-534) の `//wp:postmeta` → `'./wp:postmeta'`、`.//wp:meta_key` → `'./wp:meta_key'`、`.//wp:meta_value` → `'./wp:meta_value'`
- [ ] `extractComments()` (L555-576) の `//wp:comment` → `'./wp:comment'`、内部の `.//wp:comment_*` → `'./wp:comment_*'`
- [ ] `extractTerms()` (L469-478) の dedup を `isset($seenSlugs[$slug])` + `$seenSlugs[$slug] = true` に変更
- [ ] **小規模 WXR（Task 0 の S サイズ）** で旧実装と新実装の抽出結果を比較し、フィールド・件数が完全一致することを確認
- [ ] `php -l src/Services/WXR/Parser.php`
- [ ] コミット: `Speed up Parser by using relative XPath and O(1) slug dedup`

**完了条件**: S サイズ WXR で抽出結果（タイトル・本文・カテゴリ・タグ・カスタムフィールド・コメント）が完全一致。

---

## Task 4: DNS 解決キャッシュの追加 (P-12)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] private static プロパティ `private static array $dnsCache = [];` を追加
- [ ] private メソッド `resolveHost(string $host): array|false` を追加（設計書参照）
- [ ] `validateUrlForFetch()` (L727-759) の `gethostbynamel($host)` を `$this->resolveHost($host)` に置換
- [ ] `php -l`
- [ ] コミット: `Cache DNS resolution per import run to avoid re-lookups across hosts`

**完了条件**: 同一ホスト URL を複数回ダウンロードしても `gethostbynamel` は1回しか呼ばれない（手動 var_dump 等で確認）。

---

## Task 5: `copyLocalFile()` のストリームコピー化 (P-10)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `copyLocalFile()` (L199-261) を `stream_copy_to_stream` ベースに書き換え:
  - `fopen($sourcePath, 'rb')` / `fopen($localPath, 'wb')` を作る
  - `stream_copy_to_stream($src, $dst, $this->maxFileSize + 1)` で1回コピー
  - 戻りバイト数で `maxFileSize` 超過を判定（超過なら `unlink` + エラー戻り）
  - 既存の `validateStoredFile()` 呼び出しは維持
- [ ] 既存のセキュリティ前提（事前 `resolveLocalFilePath()` で `validateDirectoryTraversalPath` を通過済み）が引き続き満たされることをコメントで明示
- [ ] `php -l`
- [ ] **手動テスト**: 旧 security-hardening タスクの LFI 試験を再実施し、`config.server.php` / `..` 系のパスが拒否されることを確認
- [ ] コミット: `Stream-copy local media files instead of loading bytes into memory`

**完了条件**: 50MB のローカルファイルを取り込んだ際のピークメモリ追加分が数 MB 以内に収まる。

---

## Task 6: cURL ダウンロードのストリーミング保存 (P-4)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `fetchSingleHop()` (L629-673) を `CURLOPT_FILE` + `CURLOPT_HEADERFUNCTION` 方式に書き換え:
  - `$tmpPath = $localPath . '.part';` を作成し、`fopen($tmpPath, 'wb')` で書き込み先を確保
  - cURL は `CURLOPT_RETURNTRANSFER => false`, `CURLOPT_FILE => $fp`, `CURLOPT_HEADERFUNCTION => fn($ch, $line) => (...)` に変更
  - `CURLOPT_HEADER => true` は **削除**
  - 既存の SSRF 防御 / SSL 検証 / `CURLOPT_MAXFILESIZE` / 30s タイムアウトは維持
- [ ] `download()` (L561-622) のリダイレクトループを `fetchSingleHop` の新 IF に合わせて更新:
  - 3xx の場合は `unlink($tmpPath)` してから次ホップへ
  - 2xx の場合は `rename($tmpPath, $localPath)` で確定
  - 失敗（cURL エラー等）も `unlink($tmpPath)` で後始末
- [ ] `extractLocationHeader()` (L678-687) はヘッダ行配列を受け取る形に変更
- [ ] `php -l`
- [ ] **手動テスト**:
  - 公開 URL（小画像）取得が成功する
  - 3xx → 2xx のリダイレクト先取得が成功する
  - 169.254.169.254 への直接／リダイレクトが両方拒否される（SSRF 後退の不在確認）
  - 50MB 級の画像で `memory_get_peak_usage(true)` の増分が数十 MB 以内
- [ ] コミット: `Stream HTTP downloads to disk instead of buffering full body in memory`

**完了条件**: 50MB のリモート画像取得でピークメモリ増分が 50MB を大幅に下回る（10MB 程度）。SSRF テストが全件期待どおり。

---

## Task 7: `MediaInfoMap` の導入と ContentProcessor の N+1 解消 (P-5)

**新規ファイル**: `src/Services/Import/MediaInfoMap.php`

- [ ] 設計書記載のとおりクラス `MediaInfoMap` を実装:
  - `__construct(array $map)`
  - `has(int $mediaId): bool` / `get(int $mediaId): ?array`
  - `static load(array $mediaMapping): self` で `SELECT media_id, media_path, media_type, media_filesize FROM media WHERE media_id IN (...)`

**ファイル**: `src/Services/Content/ContentProcessor.php`

- [ ] コンストラクタに `MediaInfoMap $mediaInfoMap` を追加（DI コンテナでデフォルト null 受け取り可、null 時は空 Map を作る）
- [ ] あるいは新しい public setter `setMediaInfoMap(MediaInfoMap $map): void` を追加（BatchProcessor から呼ぶ）
- [ ] `getMediaInfo($mediaId)` を `$this->mediaInfoMap->get($mediaId)` 参照に差し替え（DB クエリ削除）
- [ ] `mediaUrlCache` のキーを `(int)$mediaId` 単独に変更（URL を混ぜない）
- [ ] `buildFileBlockHtml()` (L346-374) の `getMediaInfo()` 重複呼び出しを削除し、`processFileBlock()` 経由で取得済みデータを渡す

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] メディア処理完了後（`processMediaBatch` 終了直後）に `$mediaInfoMap = MediaInfoMap::load($mediaMapping);` を作成
- [ ] エントリー処理に入る前に `$this->contentProcessor->setMediaInfoMap($mediaInfoMap);` を呼ぶ
- [ ] `php -l`
- [ ] **回帰確認**: M サイズ WXR で本文中 `<img>` / `<a>` の URL 書換結果が旧実装と完全一致
- [ ] コミット: `Preload media info into MediaInfoMap to remove ContentProcessor N+1 queries`

**完了条件**: 1000エントリ・500メディアの取り込みで `media` テーブルへの SELECT が「Map 構築の1回 + 既存処理由来の数回」に収まる。

---

## Task 8: `SortValueAllocator` の導入と EntryImporter のクエリ削減 (P-6)

**新規ファイル**: `src/Services/Import/SortValueAllocator.php`

- [ ] 設計書記載のとおりクラス `SortValueAllocator` を実装:
  - コンストラクタで `EntryRepository` を受け取り、`$blogId` の `nextSort` を1回だけ取得して保持
  - `allocateEntrySort(): int` は単純な `$this->nextEntrySort++`
  - `allocateUserSort(int $userId, int $blogId): int` は per-user の lazy 初期化＋払い出し
  - `allocateCategorySort(?int $categoryId, int $blogId): int` も同上

**ファイル**: `src/Services/Import/EntryImporter.php`

- [ ] `createNewEntry()` (L69-111) のシグネチャを変更し、`SortValueAllocator $allocator` を受け取る（既存のシグネチャ互換のため `importEntry()` 側でデフォルト null + null時はその場で生成も可）
- [ ] `insertEntryData()` (L127-167) のシグネチャに `?int $mainCategoryId, SortValueAllocator $allocator` を追加し、内部の `determineMainCategory` 呼び出し（L136）と `nextEntrySort` / `nextEntryUserSort` / `nextEntryCategorySort` 呼び出し（L160-162）を削除して `$allocator->allocate*()` に置換
- [ ] `createNewEntry()` 内の `determineMainCategory` を1回だけ呼び出し、`insertEntryData()` に渡す
- [ ] `associateSubCategories()` (L275-308) のシグネチャに `int $blogId` を追加。`ACMS_RAM::entryBlog($eid)` 呼び出し（L294）を削除。インサート部を `SQL::newBulkInsert('entry_sub_category')` ベースに変更（設計書参照）
- [ ] `associateTags()` (L320-348) のシグネチャに `int $blogId` を追加。`ACMS_RAM::entryBlog($eid)` 呼び出し（L329）を削除。インサート部を `SQL::newBulkInsert('tag')` ベースに変更

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] `processEntryBatch()` の各バッチ開始時に `$allocator = new SortValueAllocator($settings['target_blog_id'], $entryRepository);` を作成
- [ ] `entryImporter->importEntry($entry, $settings, $categoryMap, $mediaMapping, $allocator)` に変更

- [ ] `php -l`
- [ ] **回帰確認**: M サイズ WXR で `entry`, `entry_sub_category`, `tag` の挿入結果が旧実装と一致（件数・関連付け）。`entry_sort` / `entry_user_sort` / `entry_category_sort` が一意かつ連続値であること
- [ ] コミット: `Reduce per-entry queries with SortValueAllocator and bulk sub-category/tag insert`

**完了条件**: 1エントリあたりの SQL 発行数が現状の概ね 1/3 以下に削減（baseline.md と比較）。

---

## Task 9: CategoryCreator トポロジカルソートへの置換 (P-7)

**ファイル**: `src/Services/Import/CategoryCreator.php`

- [ ] `sortCategoriesByHierarchy()` (L74-108) を DFS ベースのトポロジカルソートに置き換え（設計書参照）:
  - `$byId` 連想配列で O(1) ルックアップ
  - `$visited` / `$visiting` フラグで循環検出
  - 親が未登録の場合は親を呼ばずに自身を追加
- [ ] 旧実装の挙動（循環は末尾追加）を維持
- [ ] `php -l`
- [ ] **単体テスト相当の確認**: 深さ5・各階層10件のツリーを投入し、ソート時間が無視できるレベル（数 ms）であること
- [ ] コミット: `Replace O(n^3) category sort with topological DFS`

**完了条件**: 100カテゴリの階層インポートで `sortCategoriesByHierarchy()` のCPU時間が 10ms 以内。

---

## Task 10: CategoryCreator Nested Set の一括計算と一括 INSERT (P-8) **【高リスク】**

**ファイル**: `src/Services/Import/CategoryCreator.php`

- [ ] `createCategories()` (L33-65) のループを「事前計算 → 一括 INSERT」フローに改修:
  1. `sortCategoriesByHierarchy()` の結果からツリーを構築
  2. 既存ツリーの最大 right 値を 1 回 SELECT で取得（`MAX(category_right) WHERE category_blog_id = ?`）
  3. DFS で各新規カテゴリの left/right をメモリ上で割り当て（offset を最大 right + 1 から開始）
  4. **既存ツリーへの挿入の場合は** `category_left/right >= insertPosition` を `+2N` で1回だけ UPDATE する。**末尾追加のみの場合は UPDATE 不要**
  5. `SQL::newBulkInsert('category')` で全行を1ステートメント INSERT
  6. `saveCategoryMetadata` / `saveFulltext` はループで（コア API のため避けられず）
- [ ] `createCategory()` (L118-185) は **末尾フォールバック** として残し、`createCategories()` が新フローを使う
- [ ] `getNextLeftRight()` (L267-307) と `updateNestedSetForInsertion()` (L315-330) は新フロー内で参照されないが、フォールバック経路で生きるため削除しない（または `@deprecated` コメントを付与）
- [ ] `php -l`
- [ ] **必須回帰**:
  - 既存カテゴリが存在しないブログでの新規インポート → 全カテゴリの left/right が連続値
  - 既存カテゴリが存在するブログでのインポート → 既存ツリーの left/right が破壊されない（ダンプ前後で `SELECT category_id, category_left, category_right` 比較）
  - 深さ5・各階層10件のツリー（合計約 110 件）で UPDATE が 0〜1 本に収まる
- [ ] **ステージング DB で必ず事前検証** してからコミット
- [ ] コミット: `Compute Nested Set bounds in memory and bulk-insert categories in one statement`

**完了条件**: 100カテゴリインポート時の UPDATE 本数が **≤ 2 本**。既存ツリー無破壊。

---

## Task 11: Parser のストリーミング化 (P-1) **【高リスク】**

**ファイル**: `src/Services/WXR/Parser.php`

- [ ] コンストラクタで `private ?string $tmpCleanPath = null;` を追加
- [ ] `parse(string $filePath): \Generator` を改修:
  1. **一時ファイル作成**: `CACHE_DIR . 'wxr-import-clean-' . uniqid() . '.xml'` を tmp パスとし、入力ファイルを 1MB チャンクで読み込んで `preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $chunk)` を適用しながら書き出す
  2. `libxml_use_internal_errors(true)` で開始
  3. `$this->reader->open($tmpCleanPath)` で 1パス目（タクソノミ抽出）
  4. `$this->reader->close()` 後、`$this->reader = new XMLReader()` で再初期化
  5. `$this->reader->open($tmpCleanPath)` で 2パス目（item yield）
  6. `finally` で `libxml_clear_errors()` + `unlink($tmpCleanPath)`
- [ ] `validateXml()` (L606-616) はノーオペレーション化 or 削除し、代わりに 1パス目終了後に `libxml_get_errors()` で fatal を検出して例外
- [ ] `LocalStorage::get`, `LocalStorage::removeIllegalCharacters` への依存を削除
- [ ] `php -l`
- [ ] **必須回帰**:
  - 不正文字を含む WXR でも S サイズで抽出結果が旧実装と完全一致
  - 100MB の WXR ファイルで `memory_get_peak_usage(true)` の Parser 寄与分が 50MB 以下
  - XML が破損している WXR で適切に例外発生
- [ ] コミット: `Stream WXR parsing via XMLReader::open and chunked illegal-char filter`

**完了条件**: 100MB WXR の Parser パス完走時にピークメモリ増分が ≤ 50MB。抽出結果が旧実装と一致。

---

## Task 12: Execute.php の 2パス・ストリーミング化と `processAll(iterable)` 拡張 (P-3) **【高リスク】**

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] `processAll()` のシグネチャを `iterable $entries, iterable $medias, array $categories, array $settings, Logger $progressLogger, ?int $expectedEntries = null, ?int $expectedMedias = null` に拡張
- [ ] 公開メソッドとして `processEntriesStream(iterable $entries, array $settings, array $categoryMap, MediaInfoMap $mediaInfoMap, array $mediaMapping, Logger $progressLogger, ?int $expectedCount = null): array` を追加
- [ ] `processEntryBatch()` の `array_chunk($entries, $batchSize)` を、`$entries` が `iterable` 前提のバッファリングロジック（N件溜まったら処理→解放）に置き換え
- [ ] 進捗計算で `count($entries)` を直接使う箇所を `$expectedCount` ベースに変更

**ファイル**: `src/POST/WxrImport/Execute.php`

- [ ] L149-172 の全件 buffering ループを **2パス・ストリーミング** に変更:
  - **Pass 1**: `parser->parse()` を foreach で読み、`WXRCategory` と `WXRMedia` だけを `array` に収集（本文は捨てる）。エントリー本文はこの時点では参照しない
  - **Pass 1 終了後**: 既存の `BatchProcessor::processCategories` 相当のフロー → `processMediaBatch` 相当のフロー → `MediaInfoMap::load`
  - **Pass 2**: `parser->parse()` をもう一度呼び、Generator のまま `entryExtractor->extractEntry()` を `yield` するラッパージェネレータを作って `BatchProcessor::processEntriesStream` に渡す
  - エントリー件数の事前ヒントとして Pass 1 で `$expectedEntries` を控えておく（attachment 以外をカウント）

- [ ] `php -l`
- [ ] **必須回帰**:
  - S / M サイズ WXR で旧実装と新実装の DB 状態が一致（Task 7 / Task 8 の確認と兼ねる）
  - L サイズ WXR で `memory_get_peak_usage(true)` が 512MB 以内に収まる
  - エラー時のロギング・進捗ロガー出力が壊れていない
- [ ] コミット: `Stream WXR import end-to-end with two-pass parser and entry generator`

**完了条件**: AC-1（512MB 環境での 100MB / 1000エントリ完走）が達成される。

---

## Task 13: フェーズ別計測の追加 (AC-11)

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] `processAll()` 戻り値に `timings` 配列を追加:
  ```php
  'timings' => [
      'parse_pass1' => ...,
      'category'    => ...,
      'media'       => ...,
      'parse_pass2' => ...,
      'entry'       => ...,
      'total'       => ...,
  ],
  ```
- [ ] 各フェーズ開始／終了で `microtime(true)` 差分を記録
- [ ] `$progressLogger->addMessage(...)` でフェーズ完了時にサマリ出力（例: "メディア処理完了: 45.6秒"）

**ファイル**: `src/POST/WxrImport/Execute.php`

- [ ] BatchProcessor 戻り値を `Logger::info('【WXRImport plugin】移行完了サマリ', $batchResults)` 等で記録

- [ ] `php -l`
- [ ] コミット: `Add phase-level timing to BatchProcessor results and progress log`

**完了条件**: 取り込み完了後、ロガー出力からどのフェーズが何秒かかったかが判別できる。

---

## Task 14: 計測比較と最終チェック

**実装変更なし。検証のみ**:

- [ ] Task 0 の baseline と after の値を比較し、`.steering/20260518-improve-performance/result.md` を作成。各 AC の達成状況を記録:
  - AC-1: 100MB WXR での `memory_limit=512M` 完走 ✓/✗
  - AC-2: `batch_size=50` の実効化 ✓/✗
  - AC-3: 1エントリあたりクエリ数 1/3 以下 ✓/✗
  - AC-4: ContentProcessor の DB ルックアップ撤廃 ✓/✗
  - AC-5: cURL ストリーミング保存 ✓/✗
  - AC-6: ローカルコピーのストリーム化 ✓/✗
  - AC-7: Parser のストリーミング化 ✓/✗
  - AC-8: パイプラインの逐次化 ✓/✗
  - AC-9: CategoryCreator アルゴリズム改善 ✓/✗
  - AC-10: 不要 sleep の整理 ✓/✗
  - AC-11: 計測フックの追加 ✓/✗
  - AC-12: 結果同一性 ✓/✗
- [ ] `find src -name '*.php' -exec php -l {} \;` で全 PHP ファイル構文チェック
- [ ] `npx phpcs --standard=phpcs.xml src/` を実行（リント設定が存在する場合）
- [ ] 旧 `20260511-security-hardening` のテストケース（LFI / SSRF / MIME 偽装 / SVG XSS）を再実施し、セキュリティ後退の不在を確認
- [ ] `git log master..HEAD --oneline` でコミット粒度を確認

**完了条件**: result.md に全 AC の達成状況が記録され、セキュリティ回帰がない。

---

## 補足: コミット粒度と衝突回避

主要ファイルへの当たり方は以下のとおり。連番順に進めることで衝突を最小化できる:

| ファイル | 触るタスク |
|----------|-----------|
| `src/Services/Import/BatchProcessor.php` | Task 1, 2, 7, 8, 12, 13 |
| `src/Services/Media/Downloader.php` | Task 2, 4, 5, 6 |
| `src/Services/WXR/Parser.php` | Task 3, 11 |
| `src/Services/Import/EntryImporter.php` | Task 8 |
| `src/Services/Import/CategoryCreator.php` | Task 9, 10 |
| `src/Services/Content/ContentProcessor.php` | Task 7 |
| `src/POST/WxrImport/Execute.php` | Task 2, 12, 13 |
| 新規ファイル | Task 1 (`MemoryLimit`), Task 7 (`MediaInfoMap`), Task 8 (`SortValueAllocator`) |

**特に高リスクな Task 10（Nested Set）と Task 11（Parser ストリーミング）と Task 12（2パス化）はステージング DB で必ず事前検証してからコミットする。**

各コミット前に `php -l` を必ず実行。
