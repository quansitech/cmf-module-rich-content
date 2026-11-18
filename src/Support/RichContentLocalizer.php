<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * 富文本字段的显式收口：粘贴清理 → 白名单清洗 → 远程图本地化 → 引用同步。
 *
 * 「引用同步」以清洗后的最终 HTML 为准（解析 data-cmf-media，旧版 data-id 兜底），而不是只看本次
 * 新导入的图片：编辑器自带上传（如 TinyMCE 经 Livewire 临时上传）写进来的
 * media id 同样要进 usages，否则会被 cmf-media:prune-orphans 当孤儿清理；
 * 正文里删掉的图片也要随之 detach。
 */
final class RichContentLocalizer
{
    public function __construct(
        private readonly RemoteImageImporter $importer,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PasteCleaner $cleaner,
        private readonly SvgCardNormalizer $svgCards,
    ) {}

    /**
     * 处理字符串 HTML 并返回最终结果（不落库、不同步引用）。
     *
     * 供正文不落在 Eloquent 属性上的宿主使用（如 JSON payload 块）：调用方拿
     * `ImportResult::html` 写回自己的存储，再用 `mediaIds()` 维护引用。
     */
    public function prepareHtml(string $html, int|string|null $uploaderId = null): ImportResult
    {
        if (trim($html) === '') {
            return new ImportResult($html);
        }

        // 公众号 SVG 卡片先把 foreignObject 翻成 <image>，后面的清洗与本地化才有东西可处理
        $prepared = $this->svgCards->normalize($html);

        if ((bool) config('cmf-rich-content.clean_paste', true)) {
            $prepared = $this->cleaner->clean($prepared);
        }

        if ((bool) config('cmf-rich-content.sanitize', true)) {
            $prepared = $this->sanitizer->sanitize($prepared);
        }

        return $this->importer->importIntoHtml($prepared, $uploaderId);
    }

    public function localize(Model $record, string $field, int|string|null $uploaderId = null): bool
    {
        // 当前原始属性包含尚未 syncOriginal 的新值，且不会触发渲染用的签名 URL accessor。
        $original = $record->getAttributes()[$field] ?? null;

        if ($original === null || $original === '') {
            MediaReferences::sync($record, $field, '');

            return false;
        }

        if (! is_string($original)) {
            return false;
        }

        // save() 返回时 Eloquent 已同步原值；提前保留实际写入前的正文。
        $previous = $record->getRawOriginal($field);
        $result = $this->prepareHtml($original, $uploaderId);
        $final = $result->html;
        $changed = $final !== $original;

        if ($changed) {
            $record->setAttribute($field, $final);
            // 保留宿主 saving 派生/校验；trait 不再从 saved 递归调用本方法。
            if (! $record->save()) {
                return false;
            }

            $final = $record->getRawOriginal($field);
        }

        MediaReferences::sync($record, $field, is_string($final) ? $final : '', is_string($previous) ? $previous : '');

        return $changed;
    }
}
