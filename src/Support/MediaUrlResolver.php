<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use Illuminate\Support\Facades\Log;
use Quansitech\Cmf\Media\Models\Media;
use Throwable;

/**
 * 读取富文本时按 data-cmf-media 重新解析媒体地址（旧版 data-id 经 src 归属确认后同样处理）。
 *
 * 正文里 `<img data-cmf-media="N" src="...">`（以及上传视频的 `<video data-cmf-media>`）
 * 的 src 是「保存那一刻」的地址；云存储
 * 开启 CDN 边缘鉴权时 `Media::url()` 带时效签名，直接落进正文，过期后图片
 * 就会被边缘拒绝（403）。渲染口径改为按 data-cmf-media 重新取当前 URL，存量正文
 * 无需迁移即可自愈。
 *
 * 兜底原则：媒体已删除 / 磁盘不可用 / 取不到 URL 时保留原 src，宁可保持旧
 * 地址也不产出坏链；URL 与原值一致时不重排 DOM（避免无谓的标记漂移）。
 */
final class MediaUrlResolver
{
    public function resolve(string $html): string
    {
        if ($html === '' || (stripos($html, '<img') === false && stripos($html, '<video') === false && stripos($html, '<image') === false)) {
            return $html;
        }

        // 升级前的正文只有 data-id；它仍要被识别，否则旧行读不到新地址。
        if (stripos($html, MediaReferences::ATTRIBUTE) === false && stripos($html, 'data-id') === false) {
            return $html;
        }

        $document = HtmlDocument::load($html);

        /** @var list<DOMElement> $elements */
        $elements = [];

        /** @var array<int, int> $candidates */
        $candidates = [];

        // 图片与上传视频都挂 data-cmf-media：视频走同一个签名地址刷新逻辑，
        // 否则云存储开 CDN 鉴权后视频链接会过期。
        foreach (['img', 'image', 'video'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $elements[] = $element;

                // 新旧属性一起收：一次查询同时覆盖两种口径，第三方的数字 data-id 只是
                // 多取一行，最终是否算引用仍由 idOf() 的 src 归属校验决定。
                foreach ([MediaReferences::ATTRIBUTE, 'data-id'] as $attribute) {
                    $id = MediaReferences::id($element->getAttribute($attribute));

                    if ($id !== null) {
                        $candidates[$id] = $id;
                    }
                }
            }
        }

        // 整段正文只查一次库：单条正文通常 1-10 张图
        $mediaMap = MediaReferences::medias(array_values($candidates));

        /** @var list<array{0: DOMElement, 1: int}> $images */
        $images = [];

        foreach ($elements as $element) {
            $id = MediaReferences::idOf($element, $mediaMap);

            if ($id !== null) {
                $images[] = [$element, $id];
            }
        }

        if ($images === []) {
            return $html;
        }

        $urls = $this->urlsOf($mediaMap, array_values(array_unique(array_column($images, 1))));

        if ($urls === []) {
            return $html;
        }

        $changed = false;

        foreach ($images as [$image, $id]) {
            $url = $urls[$id] ?? null;

            if ($url === null) {
                continue;
            }

            $attributes = strtolower($image->tagName) === 'image' ? ['href', 'xlink:href'] : ['src'];
            foreach ($attributes as $attribute) {
                if ($image->getAttribute($attribute) !== $url) {
                    $image->setAttribute($attribute, $url);
                    $changed = true;
                }
            }
        }

        return $changed ? HtmlDocument::bodyHtml($document) : $html;
    }

    /**
     * 从已预取的媒体里取当前地址：单张失败只记日志并跳过，保留原 src。
     *
     * @param  array<int, Media>  $mediaMap
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function urlsOf(array $mediaMap, array $ids): array
    {
        $urls = [];

        foreach ($ids as $id) {
            $media = $mediaMap[$id] ?? null;

            if (! $media instanceof Media) {
                continue;
            }

            try {
                $url = $media->url();
            } catch (Throwable $exception) {
                Log::warning('cmf-rich-content.media-url.failed', [
                    'media_id' => $media->getKey(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            if (is_string($url) && $url !== '') {
                $urls[(int) $media->id] = $url;
            }
        }

        return $urls;
    }
}
