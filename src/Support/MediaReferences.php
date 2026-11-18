<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 正文附件 ID 的唯一口径：只信任 img 节点上完整的正整数，不把 UUID/数字前缀当媒体。
 */
final class MediaReferences
{
    /**
     * 正文图片的媒体引用属性（唯一可信口径）。
     *
     * 不用通用的 data-id：第三方编辑器/公众号正文常自带数字 data-id，
     * 会把「原文自带属性」误当成媒体引用（漏下载或串图）。
     */
    public const ATTRIBUTE = 'data-cmf-media';

    public static function id(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    /**
     * 旧版引用属性（data-id）的值。
     *
     * 只用于把升级前存下的正文自愈成新属性，判断逻辑本身不信任它——
     * 第三方内容里的数字 data-id 与媒体 id 同域，必须靠 src 归属再确认。
     */
    public static function legacyId(mixed $value): ?int
    {
        return self::id($value);
    }

    /**
     * 正文里可能带媒体引用的元素：图片、SVG 卡片图（公众号动画），以及上传到媒体库的视频。
     *
     * @return list<DOMElement>
     */
    private static function mediaElements(\DOMDocument $document): array
    {
        $elements = [];

        foreach (['img', 'image', 'video'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /** @return list<int> */
    public static function ids(string $html): array
    {
        if (stripos($html, self::ATTRIBUTE) === false) {
            return [];
        }

        $ids = [];

        foreach (self::mediaElements(HtmlDocument::load($html)) as $element) {
            $id = self::id($element->getAttribute(self::ATTRIBUTE));

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * 正文里的全部媒体引用：新属性 + 经 src 归属确认的旧属性。
     *
     * 同步引用时用它——升级前的正文只有 data-id，只认新属性会在「只改其它字段」
     * 的保存里把引用清空，进而被 prune 当孤儿删掉。
     *
     * @param  array<int, Media>|null  $mediaMap
     * @return list<int>
     */
    public static function allIds(string $html, ?array $mediaMap = null): array
    {
        if (stripos($html, self::ATTRIBUTE) === false && stripos($html, 'data-id') === false) {
            return [];
        }

        $ids = [];
        $legacy = [];

        foreach (self::mediaElements(HtmlDocument::load($html)) as $element) {
            $id = self::id($element->getAttribute(self::ATTRIBUTE));
            if ($id !== null) {
                $ids[$id] = $id;
            }

            $id = self::legacyId($element->getAttribute('data-id'));
            if ($id !== null) {
                $source = strtolower($element->tagName) === 'image'
                    ? ($element->getAttribute('href') ?: $element->getAttribute('xlink:href'))
                    : $element->getAttribute('src');
                $legacy[$id][] = $source;
            }
        }

        $mediaMap ??= self::medias(array_keys($legacy));
        foreach ($legacy as $id => $sources) {
            foreach ($sources as $source) {
                if (self::legacyMedia($id, $source, $mediaMap) !== null) {
                    $ids[$id] = $id;
                    break;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * 单个 img 的媒体引用：优先新属性，旧属性必须能确认指向同一张媒体才认。
     *
     * 只比数字会把第三方正文里凑巧同号的 data-id 当成引用（串图），所以旧属性
     * 一律再过一遍 src 归属校验。
     */
    /**
     * @param  array<int, Media>|null  $mediaMap  预取的媒体（按 id 索引），避免逐图查库
     */
    public static function idOf(DOMElement $image, ?array $mediaMap = null): ?int
    {
        $id = self::id($image->getAttribute(self::ATTRIBUTE));

        if ($id !== null) {
            return $id;
        }

        $legacy = self::id($image->getAttribute('data-id'));

        if ($legacy === null) {
            return null;
        }

        return self::legacyMedia($legacy, $image->getAttribute('src'), $mediaMap) !== null ? $legacy : null;
    }

    /**
     * 旧属性（data-id）指向的媒体：必须是真实记录，且 src 确实是这张媒体的地址。
     *
     * 升级前的正文只靠 id 认引用；为了让这些行在「只改标题」的保存里也不丢
     * 引用，同步与渲染都会走这里做二次确认，而不是直接信任 data-id。
     */
    /**
     * @param  array<int, Media>|null  $mediaMap
     */
    public static function legacyMedia(int $id, string $src, ?array $mediaMap = null): ?Media
    {
        $src = trim($src);

        if ($src === '') {
            return null;
        }

        if ($mediaMap !== null) {
            $media = $mediaMap[$id] ?? null;
        } else {
            /** @var class-string<Media> $model */
            $model = (string) config('cmf-media.model', Media::class);
            $media = $model::withTrashed()->find($id);
        }

        if (! $media instanceof Media || ! self::pointsAtMedia($src, $media)) {
            return null;
        }

        return $media;
    }

    /**
     * 批量取媒体（按 id 索引），供渲染时一次查询解析多张旧格式图。
     *
     * @param  list<int>  $ids
     * @return array<int, Media>
     */
    public static function medias(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var class-string<Media> $model */
        $model = (string) config('cmf-media.model', Media::class);

        return $model::withTrashed()->whereIn('id', $ids)->get()
            ->keyBy(static fn (Media $media): int => (int) $media->id)->all();
    }

    /**
     * src 是否指向该媒体的对象：同 URL，或同一磁盘根下以该媒体 path 结尾。
     *
     * 签名地址每次都不同，不能只比整串 URL；同时要比 path 而不是仅比目录前缀，
     * 否则同目录下另一张图会被误认。
     */
    public static function pointsAtMedia(string $src, Media $media): bool
    {
        $mediaUrl = (string) $media->url();

        if ($mediaUrl !== '' && $mediaUrl === $src) {
            return true;
        }

        $mediaPath = ltrim((string) $media->path, '/');

        if ($mediaPath === '') {
            return false;
        }

        $path = (string) parse_url($src, PHP_URL_PATH);

        // 以完整对象 path 结尾才算同一张图；只比目录前缀会把同目录其它文件认错
        if ($path === '' || ! str_ends_with($path, '/'.$mediaPath)) {
            return false;
        }

        // host 以媒体自身 URL 为准：裸域云盘、带路径前缀的 CDN、相对路径的本地盘
        // 都能覆盖，也不再依赖 filesystems 配置里是否写了 url。
        $mediaHost = (string) parse_url($mediaUrl, PHP_URL_HOST);
        $srcHost = (string) parse_url($src, PHP_URL_HOST);

        if ($mediaHost !== '') {
            return $srcHost === '' || strcasecmp($mediaHost, $srcHost) === 0;
        }

        // 媒体地址是相对路径（本地盘）：允许相对 src，或本站绝对地址
        return $srcHost === ''
            || strcasecmp($srcHost, (string) parse_url((string) config('app.url'), PHP_URL_HOST)) === 0;
    }

    /**
     * 按最终 HTML 整体替换引用；不存在的媒体不能插入外键，清空正文也需要 detach。
     */
    public static function sync(Model $record, string $field, string $html): void
    {
        if (! method_exists($record, 'syncMedia')) {
            return;
        }

        $ids = self::allIds($html);

        if ($ids !== []) {
            /** @var class-string<Media> $model */
            $model = (string) config('cmf-media.model', Media::class);
            $ids = $model::withTrashed()->whereIn('id', $ids)->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)->all();
        }

        $record->syncMedia($ids, $field);
    }
}
