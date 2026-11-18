<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use DOMNode;

/**
 * 公众号 SVG 动画卡片的形态归一。
 *
 * 公众号把轮播动画写成 `<svg viewBox><g><foreignObject><svg style="background-image:url(...)">`，
 * 动画由 `<g>` 上的 `animateTransform` 平移驱动。`foreignObject` 既能塞任意 HTML（清洗器不敢放行），
 * TinyMCE 的 schema 也不认识它，因此这里在清洗之前把「foreignObject + 背景图」翻译成语义等价的
 * `<image href>`：动画结构（g / animateTransform）原样保留，图片地址交给 RemoteImageImporter 本地化。
 *
 * 找不到背景图的 foreignObject 直接丢弃——它本来就是空壳，留着只会挡住后续清洗。
 */
final class SvgCardNormalizer
{
    /** @var string 从 style 里取 background-image: url(...) 的地址 */
    private const BACKGROUND_PATTERN = '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i';

    /** @var string 从 viewBox="minX minY width height" 里取宽高 */
    private const VIEWBOX_PATTERN = '/^\s*-?[\d.]+\s+-?[\d.]+\s+([\d.]+)\s+([\d.]+)\s*$/';

    public function normalize(string $html): string
    {
        if ($html === '' || stripos($html, '<svg') === false) {
            return $html;
        }

        $document = HtmlDocument::load($html);
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        if (! $this->rewrite($body)) {
            return $html;
        }

        return HtmlDocument::bodyHtml($document);
    }

    private function rewrite(DOMNode $node): bool
    {
        $changed = $node instanceof DOMElement && strtolower($node->tagName) === 'g'
            ? $this->backupOpacityAnimation($node)
            : false;

        foreach (HtmlDocument::childNodes($node) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->tagName) === 'foreignobject') {
                $this->replaceForeignObject($child);
                $changed = true;

                continue;
            }

            if ($this->rewrite($child)) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function backupOpacityAnimation(DOMElement $group): bool
    {
        $insideSvg = false;

        for ($ancestor = $group->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (strtolower($ancestor->tagName) === 'svg') {
                $insideSvg = true;

                break;
            }
        }

        if (! $insideSvg) {
            return false;
        }

        $keys = ['attributename' => 'attributeName', 'repeatcount' => 'repeatCount', 'keytimes' => 'keyTimes', 'keysplines' => 'keySplines', 'calcmode' => 'calcMode'];

        foreach (HtmlDocument::childNodes($group) as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'animate') {
                continue;
            }

            $attributes = [];

            foreach ($child->attributes as $attribute) {
                $name = strtolower($attribute->name);

                // 微信给动画节点统一附加 box-sizing 样式；它不参与 SMIL，不进入恢复声明。
                if ($name === 'style') {
                    continue;
                }

                $attributes[$keys[$name] ?? $name] = $attribute->value;
            }

            $animation = SvgOpacityAnimation::normalize($attributes);

            if ($animation !== null) {
                $json = json_encode($animation, JSON_THROW_ON_ERROR);
                $changed = $group->getAttribute(SvgOpacityAnimation::ATTRIBUTE) !== $json;
                $group->setAttribute(SvgOpacityAnimation::ATTRIBUTE, $json);

                return $changed;
            }
        }

        return false;
    }

    private function replaceForeignObject(DOMElement $foreignObject): void
    {
        $parent = $foreignObject->parentNode;

        if ($parent === null) {
            return;
        }

        $source = $this->backgroundSource($foreignObject);

        if ($source === null) {
            $parent->removeChild($foreignObject);

            return;
        }

        [$width, $height] = $this->frameSize($foreignObject);

        $image = $foreignObject->ownerDocument->createElement('image');
        $image->setAttribute('href', $source);
        // 兼容只认 xlink 的旧浏览器；清洗器同时允许这两个属性。
        $image->setAttribute('xlink:href', $source);
        $image->setAttribute('x', '0');
        $image->setAttribute('y', '0');
        $image->setAttribute('width', $width);
        $image->setAttribute('height', $height);
        $image->setAttribute('preserveAspectRatio', 'xMidYMid slice');

        $parent->replaceChild($image, $foreignObject);
    }

    /** 在 foreignObject 子树里找第一个 background-image 地址。 */
    private function backgroundSource(DOMElement $foreignObject): ?string
    {
        foreach ([$foreignObject, ...$this->descendants($foreignObject)] as $element) {
            $style = $element->getAttribute('style');

            if ($style === '' || preg_match(self::BACKGROUND_PATTERN, $style, $matches) !== 1) {
                continue;
            }

            $source = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));

            if ($source !== '' && preg_match('~^https?://~i', $source) === 1) {
                return $source;
            }
        }

        return null;
    }

    /**
     * 画框尺寸：优先取最近祖先 svg 的 viewBox，其次取它的 width/height 属性。
     *
     * @return array{0: string, 1: string}
     */
    private function frameSize(DOMElement $element): array
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) !== 'svg') {
                continue;
            }

            $viewBox = trim($node->getAttribute('viewBox') ?: $node->getAttribute('viewbox'));

            if (preg_match(self::VIEWBOX_PATTERN, $viewBox, $matches) === 1) {
                return [$matches[1], $matches[2]];
            }

            $width = trim($node->getAttribute('width'));
            $height = trim($node->getAttribute('height'));

            if (preg_match('/^[\d.]+$/', $width) === 1 && preg_match('/^[\d.]+$/', $height) === 1) {
                return [$width, $height];
            }
        }

        return ['100%', '100%'];
    }

    /** @return list<DOMElement> */
    private function descendants(DOMElement $element): array
    {
        $elements = [];

        foreach ($element->getElementsByTagName('*') as $descendant) {
            if ($descendant instanceof DOMElement) {
                $elements[] = $descendant;
            }
        }

        return $elements;
    }
}
