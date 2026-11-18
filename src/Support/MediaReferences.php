<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaManager;

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
        $elements = self::mediaElements(HtmlDocument::load($html));

        foreach ($elements as $element) {
            $id = self::id($element->getAttribute(self::ATTRIBUTE));
            if ($id !== null) {
                $ids[$id] = $id;
            }

        }

        $legacy = [];
        foreach ($elements as $element) {
            $reference = self::legacyReference($element);
            if ($reference !== null) {
                [$id, $source] = $reference;
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
     * 新采纳和渲染使用当前媒体地址确认；历史地址漂移仅在同步既有 usage 时兼容。
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

    /** 签名 query 可变，但域名、协议、端口和完整路径须一致。 */
    public static function pointsAtMedia(string $src, Media $media): bool
    {
        $mediaUrl = (string) $media->url();
        $actual = self::urlIdentity($src);
        $expected = self::urlIdentity($mediaUrl);

        if ($actual === null || $expected === null) {
            return false;
        }

        if ($expected[0] === '' && $actual[0] !== '') {
            // 本地盘可能给出根相对 URL，而编辑器可能把它扩展为本站绝对地址。
            $app = self::urlIdentity(rtrim((string) config('app.url'), '/').'/');
            if ($app !== null && $app[0] === $actual[0]) {
                $actual[0] = '';
            }
        }

        return $actual === $expected;
    }

    /** @return array{string, string}|null */
    private static function urlIdentity(string $url): ?array
    {
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if ($parts === false || ! str_starts_with($path, '/') || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' && $scheme === '' && ! str_starts_with($url, '//')) {
            return ['', $path];
        }

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [$scheme.'://'.$host.':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $path];
    }

    /** @return array{int, string}|null */
    private static function legacyReference(DOMElement $element): ?array
    {
        $id = self::legacyId($element->getAttribute('data-id'));
        $source = trim(strtolower($element->tagName) === 'image'
            ? ($element->getAttribute('href') ?: $element->getAttribute('xlink:href'))
            : $element->getAttribute('src'));

        return $id !== null && $source !== '' ? [$id, $source] : null;
    }

    /** @return array<int, array<string, string>> */
    private static function legacyNodes(string $html): array
    {
        if (stripos($html, 'data-id') === false) {
            return [];
        }

        $nodes = [];
        foreach (self::mediaElements(HtmlDocument::load($html)) as $element) {
            $reference = self::legacyReference($element);
            if ($reference !== null) {
                [$id, $source] = $reference;
                $nodes[$id][strtolower($element->tagName).':'.$source] = $source;
            }
        }

        return $nodes;
    }

    /** @return list<int> */
    private static function retainedLegacyIds(Model $record, string $field, string $html, mixed $previous): array
    {
        if (! is_string($previous) || ! method_exists($record, 'mediaUsages')) {
            return [];
        }

        $current = self::legacyNodes($html);
        $previousNodes = array_intersect_key(self::legacyNodes($previous), $current);
        if ($previousNodes === []) {
            return [];
        }

        $canonical = array_fill_keys(self::ids($previous), true);
        $existing = $record->mediaUsages()->where('field', $field)->whereIn('media_id', array_keys($previousNodes))
            ->pluck('media_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $retained = [];
        $changed = [];
        foreach ($existing as $id) {
            $before = $previousNodes[$id];
            $after = $current[$id];
            $shared = array_intersect_key($before, $after);
            if ($shared === []) {
                continue;
            }

            // 节点集合未变才能无配置保留；删除可信节点后，不能让同号节点继承引用。
            if (! isset($canonical[$id]) && count($shared) === count($before) && count($shared) === count($after)) {
                $retained[] = $id;
            } else {
                $changed[$id] = $shared;
            }
        }

        $roots = (array) config('cmf-rich-content.legacy_media_roots', []);
        if ($roots !== []) {
            foreach (self::medias(array_keys($changed)) as $id => $media) {
                if (! $media->path) {
                    continue;
                }

                foreach ((array) ($roots[$media->disk] ?? []) as $root) {
                    foreach ($changed[$id] as $source) {
                        if (self::pointsAtHistoricalMedia($source, $root, (string) $media->path)) {
                            $retained[] = $id;

                            continue 3;
                        }
                    }
                }
            }
        }

        return $retained;
    }

    private static function pointsAtHistoricalMedia(string $source, mixed $root, string $objectPath): bool
    {
        $prefix = '';
        $authType = '';
        if (is_array($root)) {
            $prefix = $root['path_prefix'] ?? '';
            $authType = $root['auth_type'] ?? '';
            $root = $root['url'] ?? null;
        }

        if (! is_string($root) || $root === '' || str_starts_with($root, '//') || strpbrk($root, '?#') !== false || ! is_string($prefix)) {
            return false;
        }

        $path = ltrim($objectPath, '/');
        $prefix = MediaManager::normalizeCdnPathPrefix($prefix);
        if ($prefix !== '') {
            $directory = $prefix.'/';
            if (! str_starts_with($path, $directory)) {
                return false;
            }

            $path = substr($path, strlen($directory));
        }

        $base = self::urlIdentity(rtrim($root, '/').'/');
        $actual = self::urlIdentity($source);
        if ($path === '' || $base === null || $actual === null || $base[0] !== $actual[0] || ! str_starts_with($actual[1], $base[1])) {
            return false;
        }

        $relative = substr($actual[1], strlen($base[1]));
        if (in_array($authType, ['', 'type_a', 'type_d'], true)) {
            return $relative === $path;
        }

        // 只接受显式配置的旧签名路径结构，不用旧密钥验签，也不据此创建引用。
        $signedSegments = match ($authType) {
            'type_b' => '[0-9]{12}/[a-f0-9]{32}',
            'type_c' => '[a-f0-9]{32}/[a-f0-9]+',
            default => null,
        };

        return $signedSegments !== null && preg_match('~\A'.$signedSegments.'/'.preg_quote($path, '~').'\z~', $relative) === 1;
    }

    /**
     * 按最终 HTML 整体替换引用；不存在的媒体不能插入外键，清空正文也需要 detach。
     */
    public static function sync(Model $record, string $field, string $html, ?string $previousHtml = null): void
    {
        if (! method_exists($record, 'syncMedia')) {
            return;
        }

        $previous = $previousHtml ?? $record->getRawOriginal($field);
        $ids = [...self::allIds($html), ...self::retainedLegacyIds($record, $field, $html, $previous)];

        if ($ids !== []) {
            /** @var class-string<Media> $model */
            $model = (string) config('cmf-media.model', Media::class);
            $ids = $model::withTrashed()->whereIn('id', $ids)->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)->all();
        }

        $record->syncMedia($ids, $field);
    }
}
