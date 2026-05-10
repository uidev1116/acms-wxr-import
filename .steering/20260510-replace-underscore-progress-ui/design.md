# 設計: WXRインポート進捗UIから Underscore.js 依存を除去

## 実装アプローチ

`<script type="text/template">` + `window._.template()` のテキストテンプレート方式を、**タグ付きテンプレートリテラル `html\`\`` （補間値を自動エスケープ）+ `innerHTML` 流し込み** 方式に置き換える。さらに、HTML 側にはルート要素 `<div id="js-background-wxr-import">` のみを置き、**配下の初期 DOM（プログレスバー・描画先コンテナ）も JS が `mount` 時に組み立てる**構成とする。

選定理由:

- 追加依存ゼロ（テンプレートリテラル / タグ関数 / `innerHTML` / `dataset` はすべて言語/ブラウザ標準）
- ビルドパイプラインを導入しなくてよい（インライン `<script>` のままで完結）
- `html\`...${value}...\`` の形で **JSX に近い書き味** を実現でき、補間値は自動的に `escapeHTML` を通るため XSS リスクが原理的に発生しない
- HTML 側にレイアウト断片を残さないため、進捗 UI の DOM 構造が **JS 1 箇所に集約** され、CSS クラスや `data-*` の整合性を取る対象がひとつになる
- 進捗 UI のテンプレートはサーバ側からのカスタマイズ要件が無いため、HTML 側に部品を散らす必要がない

## 制約事項（i18n）

a-blog cms の `<!--T-->...<!--/T-->` 翻訳マーカーは PHP テンプレート側でしか展開されない。本設計では進捗 UI の静的メッセージ 4 件（"インポートが完了しました。" / "インポートに失敗しました。" / "インポート中..." / タイムアウトメッセージ）を JS 内に日本語で直書きするため、当該 UI の他言語ローカライズは諦める方針とする（要求側で本プラグインの多言語サポート要件はない）。

## JS フック方針

旧実装は `js-progress` / `js-processing-template` / `js-processing-box` など **複数の `js-*` フッククラス** を子要素に振り分けていた。これを以下の方針で再整理する。

- **JS 用フックは `id="js-background-wxr-import"` の 1 つに集約**する。
- HTML 側に置くのはルート要素だけ。配下の DOM は JS が `mount` 時に生成・挿入する。
- 生成された子要素を JS 側で querySelector するためのフックとして `data-*` 属性を用いる（CSS スタイル用クラスとは責務分離）。
- ルート要素の `data-*` 属性に **動作設定値（ポーリング間隔・タイムアウト閾値）** を持たせ、HTML 側から挙動を組み替え可能にする。

| ルートの data 属性 | 役割 | 既定値 |
|---|---|---|
| `data-poll-interval` | ポーリング間隔 (ms) | `2000` |
| `data-timeout-ms` | タイムアウト判定閾値 (ms) | `180000` |

| JS が生成する子要素の data 属性 | 役割 |
|---|---|
| `data-progress` | プログレスバー全体（`display` 切替対象） |
| `data-progress-bar` | プログレスバー本体（幅・色クラスを変える要素） |
| `data-progress-message` | プログレスバー内のメッセージ要素 |
| `data-box` | 描画先コンテナ（アラートとリストを再構築する場所） |

## 変更後の DOM 構造（HTML 側）

```html
<div
  id="js-background-wxr-import"
  data-poll-interval="2000"
  data-timeout-ms="180000"
></div>
```

旧コードとの差分:

- `<script type="text/template" class="js-processing-template">` を **削除**
- 進捗バー (`<div class="js-progress acms-admin-progress ...">`) と描画先 (`<div class="js-processing-box">`) を **HTML から削除**し、JS の `mount` 時に組み立てるように移管
- `js-*` クラスはルートの `id="js-background-wxr-import"` のみ残す

## JS が組み立てる初期 DOM 構造

`mount(root)` が `root.innerHTML` にタグ付きテンプレートリテラル経由で挿入する DOM:

```html
<div data-progress class="acms-admin-progress acms-admin-progress-striped acms-admin-active" style="display: none;">
  <div data-progress-bar class="acms-admin-progress-bar">
    <span data-progress-message></span>
  </div>
</div>
<div data-box></div>
```

## 変更後の JavaScript 構造

主要関数を以下に分割し、責務を明示する:

| 関数 | 役割 |
|---|---|
| `escapeHTML(value)` | 文字列中の `& < > " '` を実体参照化する基盤関数。 |
| `html(strings, ...values)` | タグ付きテンプレートリテラル本体。補間値を `escapeHTML` で通したうえで結合。 |
| `mount(root)` | ルート要素配下に初期 DOM を生成し、子要素参照と設定値（`pollInterval` / `timeoutMs`）をまとめて返す。 |
| `fetchProgress()` | サーバへポーリング `fetch`。JSON を返す。失敗時は throw。 |
| `applyTimeout(json, timeoutMs)` | `updatedAt` から `timeoutMs` 超なら `json.error` をセットする副作用的処理。 |
| `renderProgressBar(json, refs)` | プログレスバーのパーセンテージ・色クラス・メッセージを更新。 |
| `renderBox(json, refs)` | 完了/失敗アラートと `processList` から描画先コンテナの中身を再構築。 |

擬似コード（要点のみ）:

```js
// ===== タグ付きテンプレートリテラルの基盤 =====
const escapeHTML = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));

// 補間値は自動でエスケープされる。生 HTML を埋めたい場合は
// 補間値が html`` 由来の文字列であることを前提とする (本実装では使わない)。
const html = (strings, ...values) => {
  let out = strings[0];
  for (let i = 0; i < values.length; i++) {
    out += escapeHTML(values[i]) + strings[i + 1];
  }
  return out;
};

// ===== 静的メッセージ（i18n 非対応） =====
const SUCCESS_TEXT          = 'インポートが完了しました。';
const FAILURE_TEXT          = 'インポートに失敗しました。';
const DEFAULT_PROGRESS_TEXT = 'インポート中...';
const TIMEOUT_TEXT          = 'タイムアウトしました。リロードして処理が完了しているか確認してください。';

// ===== 初期 DOM 構築 + 参照解決 =====
const mount = (root) => {
  // 初期 DOM 断片はすべて静的なので補間値なし。
  // それでも一貫性のため html`` を経由する。
  root.innerHTML = html`
    <div
      data-progress
      class="acms-admin-progress acms-admin-progress-striped acms-admin-active"
      style="display: none;"
    >
      <div data-progress-bar class="acms-admin-progress-bar">
        <span data-progress-message></span>
      </div>
    </div>
    <div data-box></div>
  `;

  return {
    progress:        root.querySelector('[data-progress]'),
    progressBar:     root.querySelector('[data-progress-bar]'),
    progressMessage: root.querySelector('[data-progress-message]'),
    box:             root.querySelector('[data-box]'),
    pollInterval:    Number(root.dataset.pollInterval) || 2000,
    timeoutMs:       Number(root.dataset.timeoutMs) || 180000,
  };
};

// ===== 部品テンプレート =====
const successAlert = () => html`
  <div role="alert" class="acms-admin-alert acms-admin-alert-info">
    ${SUCCESS_TEXT}
  </div>
`;

const failureAlert = () => html`
  <div role="alert" class="acms-admin-alert acms-admin-alert-warning">
    ${FAILURE_TEXT}
  </div>
`;

const item = ({ status, message }) => {
  const cls  = status === 'ng' ? 'acms-admin-text-danger' : '';
  const text = status === 'ng' ? `[Error] ${message}` : message;
  return html`<li class="${cls}"><span>${text}</span></li>`;
};

// ===== 描画ロジック =====
const renderBox = (json, refs) => {
  const parts = [];

  if (!json.processing && json.success) {
    parts.push(successAlert());
  } else if (!json.processing && json.error) {
    parts.push(failureAlert());
  }

  // item() の戻り値はすでにエスケープ済み HTML なので、ここは素の
  // テンプレートリテラルで連結する (再エスケープすると壊れる)。
  const items = (json.processList ?? []).map(item).join('');
  parts.push(`<ul>${items}</ul>`);

  refs.box.innerHTML = parts.join('');
};

const renderProgressBar = (json, refs) => {
  const { progress, progressBar, progressMessage } = refs;
  if (!json.processing) {
    progress.style.display = 'none';
    return;
  }
  progress.style.display = '';
  if (json.error) {
    progressBar.style.width = '100%';
    progressBar.classList.add('acms-admin-progress-bar-danger');
    progressBar.classList.remove('acms-admin-progress-bar-info');
    if (progressMessage) progressMessage.textContent = json.error;
  } else {
    progressBar.style.width = `${json.percentage ?? 0}%`;
    progressBar.classList.add('acms-admin-progress-bar-info');
    progressBar.classList.remove('acms-admin-progress-bar-danger');
    if (progressMessage) progressMessage.textContent = json.inProgress || DEFAULT_PROGRESS_TEXT;
  }
};

// ===== エントリーポイント =====
document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('js-background-wxr-import');
  if (!root) return;

  const refs = mount(root);
  const intervalId = setInterval(async () => {
    try {
      const json = await fetchProgress();
      applyTimeout(json, refs.timeoutMs); // 必要に応じて clearInterval
      renderProgressBar(json, refs);
      renderBox(json, refs);
      if (!json.processing) clearInterval(intervalId);
    } catch (e) {
      console.error('Progress check failed:', e);
      clearInterval(intervalId);
    }
  }, refs.pollInterval);
});
```

## XSS 対策

- 動的値の DOM 注入は次の 2 系統に限定する:
  1. `html\`...${value}...\`` のタグ付きテンプレートリテラル → 補間値が自動的に `escapeHTML` を通る
  2. `progressMessage.textContent = value` → DOM API 自体が安全
- `innerHTML` への直接代入は **`html\`\`` 由来の文字列のみ**。素のテンプレートリテラル（バッククオート）に変数を埋めたまま `innerHTML` へ流すことは禁止する（実装レビュー観点）。
- `escapeHTML` は属性値内（クォート内）でも安全になるよう `"` と `'` もエスケープする。

## 影響範囲分析

| 区分 | 影響 | 内容 |
|---|---|---|
| `src/template/admin/main.html` | あり | 進捗UIブロック (37〜165 行付近) を書き換え |
| バックエンド (`src/POST/`, `src/Services/`, `ServiceProvider.php`) | なし | API レスポンス形式・トリガー名は変更しない |
| `src/template/admin/topicpath.html` | なし | 触らない |
| インポート前のフォーム部 (167〜271 行) | なし | 触らない |
| 既存 CSS クラス（`acms-admin-*`） | なし | クラス名は据え置き。これらは JS 生成 DOM に付与する |
| `package.json` / 依存パッケージ | なし | 追加・削除なし |

なお、旧フッククラス (`js-progress` / `js-processing-template` / `js-processing-box`) を **本リポジトリ内の他箇所が参照していないこと** を実装前に確認する（grep）。参照が無いことを確認できた場合のみ廃止可能。

## 互換性検討

- タグ付きテンプレートリテラル: ES2015 以降の標準。モダンブラウザ全対応。
- `??`（nullish coalescing）: モダンブラウザ全対応。
- `dataset`: モダンブラウザ全対応。
- `innerHTML`: 全ブラウザ対応。

## ロールバック方針

変更は `src/template/admin/main.html` 1 ファイルのみ。問題発生時は `git revert` 1 コミットで原状復帰可能。

## 検証方法

1. 管理画面にログインし、WordPress のエクスポート XML を選択してインポート実行。
2. ページ表示直後、ルート要素 (`#js-background-wxr-import`) 配下にプログレスバー (`[data-progress]`) と描画先 (`[data-box]`) が JS によって挿入されていることを DevTools で確認。
3. プログレスバーが表示され、パーセンテージ・色・メッセージが従来同様更新されることを確認。
4. インポート進行中、`<ul>` の `<li>` メッセージが従来同様表示されることを確認。
5. インポート完了時、`acms-admin-alert-info` の完了アラートが表示されることを確認。
6. 故意にエラーを発生させ（不正な XML 等）、`acms-admin-alert-warning` の失敗アラートが表示されることを確認。
7. ブラウザ DevTools で `<script>alert(1)</script>` のような文字列を含む `message` を仕込んだ場合に、スクリプトが実行されず `&lt;script&gt;...` として表示されることを確認（タグ付き `html\`\`` の自動エスケープが効いている確認）。
8. ブラウザコンソールに `_ is not defined` 等のエラーが出ないことを確認。
9. ルート要素の `data-poll-interval="500"` のように一時的に書き換え、ポーリング間隔が反映されることを確認（DSL 化が動いている確認）。
