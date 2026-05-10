# タスクリスト: WXRインポート進捗UIから Underscore.js 依存を除去

対象ファイル: `plugins/WXRImport/src/template/admin/main.html` （1 ファイル）

## 進捗ステータス記法

- `[ ]` 未着手
- `[~]` 進行中
- `[x]` 完了

---

## 1. 事前調査

- [ ] **1-1.** 旧フッククラスがリポジトリ内で参照されていないことを確認する。
  - `js-progress` / `js-processing-template` / `js-processing-box` / `js-background-wxr-import` を `plugins/WXRImport/` 配下で grep。
  - 参照箇所が `src/template/admin/main.html` のみであれば OK。`js-background-wxr-import` は今回の設計でも残す ID。
  - **完了条件:** grep 結果から、廃止対象 (`js-progress` / `js-processing-template` / `js-processing-box`) の参照が `main.html` 以外に無いと確認できる。

- [ ] **1-2.** バックエンド側のレスポンス形 (`processing` / `success` / `error` / `inProgress` / `percentage` / `processList[].status` / `processList[].message` / `updatedAt`) を `src/POST/` / `src/Services/` 内のソースで再確認する。
  - 既存 JS が前提にしていたフィールドが実装と合っていることを確認するだけ。コード変更は不要。
  - **完了条件:** 各フィールドが実装側で確かに返されていることをソースで確認した。

---

## 2. HTML 構造の書き換え

- [ ] **2-1.** `src/template/admin/main.html` 37〜69 行（旧 `<div id="js-background-wxr-import">` ブロック）を、ルート要素 1 個の構造に置き換える。
  - 新構造:
    ```html
    <div
      id="js-background-wxr-import"
      data-poll-interval="2000"
      data-timeout-ms="180000"
    ></div>
    ```
  - 旧 `<div class="js-progress ...">` / `<script type="text/template" class="js-processing-template">` / `<div class="js-processing-box">` はすべて削除。
  - **完了条件:** HTML 側に進捗UIの DOM 断片が残っていない（ルート要素のみ）。

---

## 3. JavaScript の書き換え

`src/template/admin/main.html` 71〜165 行のインライン `<script>` を全面差し替え。

- [ ] **3-1.** タグ付きテンプレートリテラルの基盤関数を実装する。
  - `escapeHTML(value)`: `& < > " '` を実体参照化。`null` / `undefined` は空文字に変換。
  - `html(strings, ...values)`: 補間値を `escapeHTML` で通したうえで連結するタグ関数。
  - **完了条件:** `escapeHTML('<a "b" \'c\'>')` が `&lt;a &quot;b&quot; &#39;c&#39;&gt;` を返すことが（コンソール等で）確認できる。

- [ ] **3-2.** 静的メッセージ定数を定義する。
  - `SUCCESS_TEXT` / `FAILURE_TEXT` / `DEFAULT_PROGRESS_TEXT` / `TIMEOUT_TEXT`。
  - **完了条件:** 旧テンプレートと同等の日本語文言が定義されている。

- [ ] **3-3.** `mount(root)` を実装する。
  - `root.innerHTML` に `html\`\`` 経由で初期 DOM を流し込む。
  - 子要素参照 (`progress` / `progressBar` / `progressMessage` / `box`) と設定値 (`pollInterval` / `timeoutMs`) を返す。
  - `Number(root.dataset.pollInterval) || 2000` のフォールバック付き。
  - **完了条件:** ページ読み込み時、ルート要素配下に `[data-progress]` / `[data-box]` 等が挿入されている。

- [ ] **3-4.** 部品テンプレート関数を実装する。
  - `successAlert()`: `acms-admin-alert-info` の完了アラート。
  - `failureAlert()`: `acms-admin-alert-warning` の失敗アラート。
  - `item({ status, message })`: `<li>` 要素。`status === 'ng'` のとき `acms-admin-text-danger` クラスを付与し、`[Error] ` プレフィックスを付ける。
  - **完了条件:** 各関数を単独で呼ぶと、想定どおりのエスケープ済み HTML 文字列が返る。

- [ ] **3-5.** `fetchProgress()` を実装する。
  - 既存の POST 仕様を踏襲: `ACMS_POST_WxrImport_ProgressJson=exec` / `formToken=window.csrfToken` / `X-CSRF-Token` / `X-Requested-With: XMLHttpRequest`。
  - レスポンスを JSON で返す。失敗時は throw。
  - **完了条件:** ネットワークタブで POST が従来同様に飛び、JSON が返る。

- [ ] **3-6.** `applyTimeout(json, timeoutMs)` を実装する。
  - `json.updatedAt` から `timeoutMs` 超過時に `json.error = TIMEOUT_TEXT` をセット。
  - 戻り値で「タイムアウトが起きたか」を返し、呼び出し側がポーリングを止められるようにする（または例外で表現）。
  - **完了条件:** 故意に古い `updatedAt` を返した場合、タイムアウトメッセージが表示される。

- [ ] **3-7.** `renderProgressBar(json, refs)` を実装する。
  - `!json.processing` → `display: none`。
  - `json.error` あり → 幅 100% / `acms-admin-progress-bar-danger` / メッセージは `json.error` を `textContent`。
  - 通常 → 幅 `${percentage}%` / `acms-admin-progress-bar-info` / メッセージは `json.inProgress || DEFAULT_PROGRESS_TEXT` を `textContent`。
  - **完了条件:** 進捗中・エラー時・完了時それぞれで期待通りの見た目になる。

- [ ] **3-8.** `renderBox(json, refs)` を実装する。
  - 完了時は `successAlert()`、失敗時は `failureAlert()` を先頭に。
  - `processList` を `item` で map → join → `<ul>${...}</ul>` で囲む。再エスケープしないこと。
  - 結果を `refs.box.innerHTML` に代入。
  - **完了条件:** メッセージリストが従来同様レンダリングされる。

- [ ] **3-9.** エントリーポイント (`DOMContentLoaded`) を実装する。
  - ルート要素を取得 → `mount(root)` → `setInterval` でポーリング。
  - 例外時 / `!json.processing` 時に `clearInterval`。
  - 既存の IIFE スコープを踏襲し、グローバル汚染を避ける。
  - **完了条件:** ページ表示で自動的にポーリングが始まり、完了時に止まる。

---

## 4. 動作検証

- [ ] **4-1.** 管理画面にログインし、正常系の WordPress エクスポート XML を選択してインポートを実行する。
  - DevTools の Elements パネルで、ルート要素配下に JS 生成の DOM (`[data-progress]` / `[data-box]`) があることを確認。
  - プログレスバーの幅・色・メッセージが進捗に応じて更新されることを確認。
  - 完了時に `acms-admin-alert-info` の完了アラートが表示され、ポーリングが止まることを確認。
  - **完了条件:** インポートが正常終了し、UI 表示も従来同等。

- [ ] **4-2.** 失敗系を試す（不正な XML をアップロードする等）。
  - `acms-admin-alert-warning` の失敗アラートが出ること。
  - プログレスバーが赤 (`acms-admin-progress-bar-danger`) になること。
  - **完了条件:** 失敗 UI が従来同等。

- [ ] **4-3.** XSS 検証。バックエンド側のテストデータ等で `<script>alert(1)</script>` を含む `message` を返した場合に、スクリプトが実行されず文字列としてエスケープ表示されることを確認。
  - 注入経路が無ければ DevTools で一時的にレスポンスを書き換えて確認可。
  - **完了条件:** `&lt;script&gt;` 等として表示され、アラートが出ない。

- [ ] **4-4.** タイムアウト挙動の確認。
  - DevTools で `updatedAt` を 200 秒以上前にレスポンス改竄。`TIMEOUT_TEXT` がプログレスバーメッセージに出てポーリングが止まること。
  - **完了条件:** タイムアウト挙動が従来同等。

- [ ] **4-5.** ルート要素の `data-poll-interval="500"` を一時的に書き換えて、ポーリング間隔が 500ms に変わることを確認（DSL が機能している証拠）。
  - 確認後は元の値 `2000` に戻す。
  - **完了条件:** `data-poll-interval` の値で挙動が変わる。

- [ ] **4-6.** ブラウザコンソールに `_ is not defined` や JS エラーが一切出ないことを確認。
  - **完了条件:** コンソールがクリーン。

---

## 5. クリーンアップ・コミット

- [ ] **5-1.** 不要になった旧 `js-*` フッククラスへの参照が残っていないかを再 grep する。
  - **完了条件:** 廃止対象クラスへの参照が完全に消えている。

- [ ] **5-2.** 変更を 1 コミットにまとめてコミット（ユーザー承認後に実施）。
  - 変更ファイルは `src/template/admin/main.html` のみ想定。
  - **完了条件:** ユーザーが承認したコミットメッセージで 1 コミット作成。

---

## 完了条件（このタスク全体）

- [ ] 上記 1〜5 のすべてのタスクが `[x]` になっている。
- [ ] `requirements.md` に列挙した受け入れ条件 1〜9 がすべて満たされている。
- [ ] `package.json` に依存追加が無く、新たなビルドパイプラインも導入されていない。
