<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMDocument;
use DOMNode;

/**
 * HTML 片段 ↔ DOMDocument 的往返工具。
 *
 * 统一按 UTF-8 加载（`<?xml encoding>` 提示），序列化时只取 <body> 的
 * 子节点，保证「片段进 → 片段出」，不额外包裹 <html>/<body>。
 */
final class HtmlDocument
{
    public static function load(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    public static function bodyHtml(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $html = '';

        foreach ($body->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * 先拷贝子节点再遍历：边遍历边增删会让 live NodeList 漏项。
     *
     * @return list<DOMNode>
     */
    public static function childNodes(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }
}
