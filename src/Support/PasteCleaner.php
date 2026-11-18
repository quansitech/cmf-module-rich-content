<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMComment;
use DOMElement;
use DOMNode;

/**
 * 粘贴来源清理：去掉 Word / 公众号 / 网页复制带进来的冗余标记。
 *
 * 只做「减噪」，不判断内容价值：Office 命名空间标签与条件注释、mso-* 样式、
 * 空段落、align 属性（转成 text-align）、font 标签（转成带 color 的 span）。
 * 白名单过滤交给 HtmlSanitizer，两者职责不重叠。
 */
final class PasteCleaner
{
    private const OFFICE_PATTERNS = [
        '/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is',
        '/<xml[^>]*>.*?<\/xml>/is',
        '#</?(?:o|w|v|m|st1|x):[^>]*>#i',
    ];

    private const EMPTY_BLOCK_TAGS = ['p', 'div', 'section', 'span'];

    public function clean(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $html = $this->stripOfficeMarkup($html);

        if (! str_contains($html, '<')) {
            return $html;
        }

        $document = HtmlDocument::load($html);
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $this->cleanChildren($body);

        return HtmlDocument::bodyHtml($document);
    }

    private function stripOfficeMarkup(string $html): string
    {
        foreach (self::OFFICE_PATTERNS as $pattern) {
            $html = (string) preg_replace($pattern, '', $html);
        }

        return $html;
    }

    private function cleanChildren(DOMNode $node): void
    {
        foreach (HtmlDocument::childNodes($node) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->tagName) === 'font') {
                $child = $this->convertFont($child);
            }

            $this->normalizeAttributes($child);
            $this->cleanChildren($child);

            if ($this->isEmptyBlock($child)) {
                $node->removeChild($child);
            }
        }
    }

    private function convertFont(DOMElement $font): DOMElement
    {
        $document = $font->ownerDocument;

        if ($document === null) {
            return $font;
        }

        $span = $document->createElement('span');
        $color = trim($font->getAttribute('color'));

        if ($color !== '' && preg_match('/^[#a-z0-9(),.%\s]+$/i', $color) === 1) {
            $span->setAttribute('style', 'color: '.$color);
        }

        while ($font->firstChild !== null) {
            $span->appendChild($font->firstChild);
        }

        $parent = $font->parentNode;

        if ($parent === null) {
            return $span;
        }

        $parent->replaceChild($span, $font);

        return $span;
    }

    private function normalizeAttributes(DOMElement $element): void
    {
        if ($element->hasAttribute('align')) {
            $align = strtolower(trim($element->getAttribute('align')));
            $element->removeAttribute('align');

            if (in_array($align, ['left', 'right', 'center', 'justify'], true)) {
                $this->appendStyle($element, 'text-align: '.$align);
            }
        }

        if (! $element->hasAttribute('style')) {
            return;
        }

        $style = $this->stripOfficeStyles($element->getAttribute('style'));

        if ($style === '') {
            $element->removeAttribute('style');
        } else {
            $element->setAttribute('style', $style);
        }
    }

    private function appendStyle(DOMElement $element, string $declaration): void
    {
        $existing = trim($element->getAttribute('style'));
        $element->setAttribute('style', $existing === '' ? $declaration : rtrim($existing, '; ').'; '.$declaration);
    }

    private function stripOfficeStyles(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if ($property === '' || $value === '') {
                continue;
            }

            // Word 的私有属性与带转义的值（\*）对浏览器无意义
            if (str_starts_with($property, 'mso-') || str_contains($value, 'mso-') || str_starts_with($value, '\\')) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        return implode('; ', $kept);
    }

    private function isEmptyBlock(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);

        if (! in_array($tag, self::EMPTY_BLOCK_TAGS, true)) {
            return false;
        }

        // 空段落是用户的换行意图：编辑器里连按回车会序列化成 <p>&nbsp;</p> / <p></p>，
        // 清掉就等于把空行吃了（UXGH-106）。这类空段落一律保留，只清理 div/section/span 包裹。
        if ($tag === 'p') {
            return false;
        }

        // 公众号的色块、线条和间隔常用带 style 的空 section/span 绘制。
        // 即使父节点没有文字，也不能连同这类装饰子节点一起清掉。
        if (trim($element->getAttribute('style')) !== '') {
            return false;
        }

        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (trim($descendant->getAttribute('style')) !== '') {
                return false;
            }
        }

        // iframe 为白名单视频嵌入（HtmlSanitizer 另有域名校验），不能当空块清掉
        // 视频/音频嵌入同样算有效内容：只装 <video> 的段落不能被当成空壳清掉，
        // 否则「正文只有视频」的文章会在校验阶段被判为空（视频白名单另由 HtmlSanitizer 把关）。
        foreach (['img', 'br', 'table', 'hr', 'iframe', 'video', 'audio', 'svg'] as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return preg_replace('/^[\s\x{00A0}\x{200B}]*$/u', '', $element->textContent) === '';
    }
}
