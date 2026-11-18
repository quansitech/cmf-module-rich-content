<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use DOMXPath;

/** 只负责正文边界、懒加载地址和安全排版基线；HTTP 与清洗/入库由编排层处理。 */
final class WechatArticleBody
{
    /** 微信页面外壳的结构标记，仅用于拒绝不可靠的正文候选，不剪裁正文。 */
    private const PAGE_CHROME_IDS = [
        'activity-name', 'meta_content', 'js_name', 'publish_time',
        'js_pc_qr_code', 'js_view_source', 'js_toobar3', 'js_bottom_ad_area',
    ];

    private const PAGE_CHROME_CLASSES = [
        'rich_media_title', 'rich_media_meta_list', 'rich_media_tool',
    ];

    /**
     * 从公众号页面 HTML 里抽出正文并还原懒加载图片地址。
     *
     * 抓取与「粘贴公众号富文本」两条入口共用：命中 #js_content / .rich_media_content
     * 时只取正文容器，避免把页面导航与脚本带进编辑器。
     */
    public function extract(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<')) {
            return '';
        }

        $document = HtmlDocument::load($html);
        $xpath = new DOMXPath($document);

        $container = $this->findArticleContainer($xpath);

        if (! $container instanceof DOMElement) {
            return '';
        }

        $this->promoteLazyImages($container);
        $this->applyArticleDefaults($container);

        $html = '';

        foreach (HtmlDocument::childNodes($container) as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        // 公众号的基础排版来自页面 CSS，正文脱离页面后需要显式携带；不复制远端样式表。
        return '<section data-type="wechat-article" style="font-size: 17px; line-height: 1.6; color: rgba(0, 0, 0, 0.9); text-align: justify; overflow-wrap: break-word">'
            .trim($html).'</section>';
    }

    /**
     * 正文必须有唯一、独立的边界；class 兜底不能把整页误当正文。
     *
     * 不按标题标签、推广文案或首尾位置删内容，避免伤及作者本来写在正文里的内容。
     */
    private function findArticleContainer(DOMXPath $xpath): ?DOMElement
    {
        $candidates = $xpath->query('//*[@id="js_content"]');

        if ($candidates === false || $candidates->length === 0) {
            $candidates = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rich_media_content ")]');
        }

        if ($candidates === false || $candidates->length !== 1) {
            return null;
        }

        $container = $candidates->item(0);

        if (! $container instanceof DOMElement || in_array(strtolower($container->tagName), ['html', 'body'], true)) {
            return null;
        }

        foreach ($xpath->query('descendant-or-self::*', $container) as $element) {
            if (in_array($element->getAttribute('id'), self::PAGE_CHROME_IDS, true)) {
                return null;
            }

            $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];

            if (array_intersect($classes, self::PAGE_CHROME_CLASSES) !== []) {
                return null;
            }
        }

        return $container;
    }

    /**
     * 补齐公众号页面赋予链接/图片的基础样式，原文内联声明放后面以保留其优先级。
     * 所有声明仍会经过 HtmlSanitizer，不把来源的 class 或样式表带入站点。
     */
    private function applyArticleDefaults(DOMElement $container): void
    {
        foreach ($container->getElementsByTagName('a') as $link) {
            $link->setAttribute('style', 'color: #576b95; text-decoration: none; '.$link->getAttribute('style'));
        }

        foreach ($container->getElementsByTagName('img') as $image) {
            $image->setAttribute('style', 'max-width: 100%; vertical-align: middle; '.$image->getAttribute('style'));
        }
    }

    private function promoteLazyImages(DOMElement $container): void
    {
        foreach ($container->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $source = $image->getAttribute('data-src') ?: $image->getAttribute('data-original');

            if ($source === '') {
                continue;
            }

            $image->setAttribute('src', $source);
            $image->removeAttribute('data-src');
            $image->removeAttribute('data-original');
            $image->removeAttribute('data-type');
            $image->removeAttribute('class');
            // 宽高、圆角、对齐等内联排版由 HtmlSanitizer 白名单审查，不整段删掉。
        }
    }
}
