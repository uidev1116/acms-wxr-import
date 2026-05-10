<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Helpers;

/**
 * コード生成ヘルパークラス
 *
 * WordPress インポート時の各種コード（slug）生成処理を共通化します。
 * エントリーコード、カテゴリーコードなどの生成とユニーク性の確保を行います。
 */
class CodeGenerator
{
    /**
     * タイトルまたは名前からスラッグを生成
     *
     * WordPress のスラッグ生成ルールに準拠して、日本語や特殊文字を含む
     * テキストからURL安全なスラッグを生成します。
     *
     * @param string $text 変換対象のテキスト
     * @param string $fallbackPrefix スラッグが生成できない場合のプレフィックス
     * @param int|string|null $fallbackId フォールバック時に使用するID
     * @return string URL安全なスラッグ
     */
    public static function generateSlug(string $text, string $fallbackPrefix = 'item', int|string|null $fallbackId = null): string
    {
        // 既存のスラッグがある場合はそのまま使用
        if ($text !== '' && preg_match('/^[a-zA-Z0-9\-_]+$/', $text)) {
            return $text;
        }

        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 全角を半角に変換
        $text = mb_convert_kana($text, 'as', 'UTF-8');

        // 小文字に変換
        $text = strtolower($text);

        // スペースをハイフンに置換
        $text = preg_replace('/\s+/', '-', $text);

        // 連続するハイフンを一つにまとめる
        $text = preg_replace('/-+/', '-', $text);

        // 英数字、ハイフン、アンダースコア以外を除去
        $text = preg_replace('/[^a-z0-9\-_]/', '', $text);

        // 前後のハイフンを除去
        $text = trim($text, '-_');

        // 空の場合はフォールバックを使用
        if ($text === '') {
            $suffix = $fallbackId !== null ? '_' . $fallbackId : '';
            return $fallbackPrefix . $suffix;
        }

        return $text;
    }

    /**
     * エントリーコードを生成（ユニーク性チェック付き）
     *
     * WordPress の投稿スラッグからa-blog cms のエントリーコードを生成します。
     * 既存のスラッグがある場合は優先的に使用し、ない場合はタイトルから生成します。
     * 重複がある場合は自動的に連番を付与してユニーク性を確保します。
     *
     * @param string|null $postName WordPress の投稿スラッグ
     * @param string $title 投稿タイトル
     * @param int $postId WordPress の投稿ID
     * @param int $blogId ブログID
     * @param int|null $categoryId カテゴリーID（nullの場合はブログ内でのみチェック）
     * @param callable $existsChecker コード存在チェック関数 (string $code, int $blogId, int|null $categoryId): bool
     * @return string ユニークなエントリーコード
     */
    public static function generateUniqueEntryCode(?string $postName, string $title, int $postId, int $blogId, ?int $categoryId, callable $existsChecker): string
    {
        // 既存のスラッグを優先
        if ($postName !== null && $postName !== '') {
            $baseCode = $postName;
        } else {
            // タイトルからスラッグを生成
            $baseCode = self::generateSlug($title, 'entry', $postId);
        }

        $counter = 0;
        $code = $baseCode;

        // 重複チェックとユニーク化
        while ($existsChecker($code, $blogId, $categoryId)) {
            $counter++;
            $code = $baseCode . '_' . $counter;
        }

        return $code;
    }

    /**
     * カテゴリーコードを生成（ユニーク性チェック付き）
     *
     * WordPress のカテゴリースラッグからa-blog cms のカテゴリーコードを生成します。
     * 重複がある場合は自動的に連番を付与してユニーク性を確保します。
     *
     * @param string $slug ベースとなるスラッグ
     * @param int $blogId ブログID
     * @param callable $existsChecker コード存在チェック関数 (string $code, int $blogId): bool
     * @return string ユニークなカテゴリーコード
     */
    public static function generateUniqueCategoryCode(string $slug, int $blogId, callable $existsChecker): string
    {
        $baseCode = self::generateSlug($slug, 'category');
        $counter = 0;
        $code = $baseCode;

        // 重複チェックとユニーク化
        while ($existsChecker($code, $blogId)) {
            $counter++;
            $code = $baseCode . '_' . $counter;
        }

        return $code;
    }

}
