<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMComment;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * 富文本 HTML 白名单清洗（保存前兜底，防 XSS）。
 *
 * - 白名单外的普通标签「去壳留内容」，危险标签（script/form/object…）连内容一起删；
 * - iframe 只保留白名单视频站（config('cmf-rich-content.embeds.allowed_hosts')）的 https 地址；
 * - 属性按标签白名单过滤，on* / class / id 一律删除，data-* 只放行白名单；
 * - style 只保留静态排版属性与明确允许的函数，拒绝资源、动态值和混淆语法；
 * - href/src 校验协议，挡掉 javascript:/vbscript:/file: 与非图片 data:；
 * - 内联 SVG 只放行静态图形与 SMIL 动画（公众号轮播卡片），不允许 foreignObject/script/外链 use。
 *
 * 目的是「允许的东西明确可枚举」，不追求保留所有排版。
 */
final class HtmlSanitizer
{
    /**
     * @param  list<string>|null  $allowedEmbedHosts  iframe 白名单，null 时读配置
     */
    public function __construct(private ?array $allowedEmbedHosts = null) {}

    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'figure', 'figcaption', 'span', 'div', 'section',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'mark', 'sup', 'sub', 'small', 'abbr', 'iframe', 'video',
    ];

    private const REMOVE_WITH_CONTENT = [
        'script', 'style', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'label',
        'link', 'meta', 'base', 'math', 'noscript', 'template', 'audio',
    ];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'scrolling', 'title', 'loading', 'referrerpolicy'],
        'video' => ['src', 'controls', 'width', 'height', 'poster', 'preload', 'playsinline', 'muted', 'loop', 'type'],
        'td' => ['colspan', 'rowspan', 'width'],
        'th' => ['colspan', 'rowspan', 'width', 'scope'],
        'ol' => ['start'],
        'col' => ['span', 'width'],
        'colgroup' => ['span'],
    ];

    private const GLOBAL_ATTRIBUTES = ['title', 'style', 'dir', 'lang'];

    private const ALLOWED_DATA_ATTRIBUTES = ['data-cmf-media', 'data-id', 'data-src', 'data-original', 'data-type'];

    /**
     * 内联 SVG 白名单：元素 => 允许的属性（全部小写比较）。
     *
     * 公众号的 SVG 动画卡片只需要 g / image / animateTransform 这一小撮元素；
     * 这里刻意不包含 foreignObject、use、script、filter，避免 SVG 变成任意 HTML 容器
     * 或加载外部资源。
     */
    private const SVG_ELEMENTS = [
        'svg' => ['viewbox', 'xmlns', 'width', 'height', 'x', 'y', 'preserveaspectratio', 'style', 'fill', 'stroke', 'opacity', 'version'],
        'g' => ['transform', 'style', 'opacity', 'fill', 'stroke', 'display', 'visibility'],
        'defs' => ['style'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'opacity', 'style', 'transform'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'style', 'transform'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'opacity', 'style'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'style'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'opacity', 'style'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'opacity', 'style'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'opacity', 'style'],
        'text' => ['x', 'y', 'dx', 'dy', 'font-size', 'font-family', 'font-weight', 'fill', 'text-anchor', 'opacity', 'style', 'transform'],
        'tspan' => ['x', 'y', 'dx', 'dy', 'font-size', 'fill', 'opacity', 'style'],
        'image' => ['href', 'xlink:href', 'x', 'y', 'width', 'height', 'preserveaspectratio', 'style'],
        'animate' => ['attributename', 'values', 'from', 'to', 'by', 'dur', 'begin', 'end', 'repeatcount', 'fill', 'keytimes', 'keysplines', 'calcmode', 'restart', 'type', 'additive', 'accumulate', 'style'],
        'animatetransform' => ['attributename', 'type', 'values', 'from', 'to', 'by', 'dur', 'begin', 'end', 'repeatcount', 'fill', 'keytimes', 'keysplines', 'calcmode', 'restart', 'additive', 'accumulate', 'style'],
        'animatemotion' => ['path', 'values', 'from', 'to', 'by', 'dur', 'begin', 'end', 'repeatcount', 'fill', 'keytimes', 'keysplines', 'calcmode', 'restart', 'style'],
        'set' => ['attributename', 'to', 'begin', 'end', 'dur', 'fill', 'repeatcount', 'style'],
        'lineargradient' => ['id', 'gradientunits', 'x1', 'y1', 'x2', 'y2', 'style'],
        'radialgradient' => ['id', 'gradientunits', 'cx', 'cy', 'r', 'fx', 'fy', 'style'],
        'stop' => ['offset', 'stop-color', 'stop-opacity', 'style'],
        'clippath' => ['id', 'clippathunits', 'style'],
        'mask' => ['id', 'maskunits', 'style'],
        'title' => [],
        'desc' => [],
    ];

    /**
     * SMIL 动画允许改写的属性：只动几何与外观。
     *
     * 必须显式限定，否则 `attributeName="href"` / `attributeName="onload"` 这类动画
     * 可以在运行时把安全属性改成脚本入口。
     */
    private const SVG_ANIMATABLE_ATTRIBUTES = [
        'transform', 'opacity', 'fill', 'stroke', 'stroke-width', 'x', 'y', 'width', 'height',
        'rx', 'ry', 'cx', 'cy', 'r', 'd', 'points', 'offset', 'display', 'visibility',
        'stop-color', 'stop-opacity',
    ];

    /** 允许承载文本的 SVG 元素，其余元素里的文本节点直接丢弃。 */
    private const SVG_TEXT_ELEMENTS = ['text', 'tspan', 'title', 'desc'];

    /** SVG 子树最大嵌套深度，防畸形/深递归输入。 */
    private const SVG_MAX_DEPTH = 24;

    private const ALLOWED_STYLES = [
        'color', 'background', 'background-color', 'display', 'box-sizing',
        'text-align', 'text-indent', 'font-weight', 'font-style', 'font-size', 'line-height',
        'text-decoration', 'text-decoration-line', 'text-decoration-style',
        'text-decoration-color', 'text-decoration-thickness', 'text-decoration-skip-ink',
        'text-underline-offset', 'text-underline-position', 'vertical-align', 'white-space',
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'flex', 'flex-flow', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'align-items', 'align-self', 'align-content', 'justify-content', 'gap', 'row-gap', 'column-gap',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'letter-spacing', 'word-spacing', 'font-family', 'word-break', 'word-wrap', 'overflow-wrap',
        'border', 'border-collapse', 'border-spacing', 'border-radius', 'border-color', 'border-width', 'border-style',
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
        'text-shadow', 'transform', 'transform-origin', 'overflow', 'overflow-x', 'overflow-y',
        // TinyMCE / 公众号粘贴的图片对齐会写成 float，保留以免「图片左右环绕」失效
        'float',
    ];

    private const ALLOWED_STYLE_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla', 'calc', 'min', 'max', 'clamp'];

    private const GRADIENT_FUNCTIONS = [
        'linear-gradient', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient',
    ];

    private const TRANSFORM_FUNCTIONS = [
        'matrix', 'translate', 'translatex', 'translatey', 'scale', 'scalex', 'scaley',
        'rotate', 'skew', 'skewx', 'skewy',
    ];

    private const BACKGROUND_COLOR_KEYWORDS = [
        'transparent', 'currentcolor',
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black',
        'blanchedalmond', 'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
        'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan',
        'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta', 'darkolivegreen',
        'darkorange', 'darkorchid', 'darkred', 'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray',
        'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey',
        'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro', 'ghostwhite',
        'gold', 'goldenrod', 'gray', 'green', 'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred',
        'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue',
        'lightcoral', 'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink',
        'lightsalmon', 'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue',
        'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue',
        'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen',
        'mediumturquoise', 'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin',
        'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab', 'orange', 'orangered', 'orchid',
        'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff', 'peru',
        'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple', 'red', 'rosybrown', 'royalblue',
        'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna', 'silver', 'skyblue',
        'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'teal', 'thistle',
        'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
    ];

    private const INTEGER_ATTRIBUTES = ['width', 'height', 'colspan', 'rowspan', 'span', 'start'];

    public function sanitize(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<')) {
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

    private function cleanChildren(DOMNode $node): void
    {
        foreach (HtmlDocument::childNodes($node) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if ($tag === 'svg') {
                // 内联 SVG（公众号动画卡片）走独立白名单，不合格的整棵子树丢弃
                if (! $this->sanitizeSvg($child)) {
                    $node->removeChild($child);
                }

                continue;
            }

            if ($tag === 'iframe') {
                // 只保留白名单视频站：来源不合规就整段删除，不留空壳
                if (! $this->isAllowedEmbed($child)) {
                    $node->removeChild($child);

                    continue;
                }

                // 协议相对地址在 http 站点会解析成 http，统一补成 https 再落库
                $src = trim($child->getAttribute('src'));

                if (str_starts_with($src, '//')) {
                    $child->setAttribute('src', 'https:'.$src);
                }

                $this->sanitizeAttributes($child, 'iframe');

                // iframe 的可见内容只在浏览器不支持 iframe 时出现，统一清空避免重复显示
                while ($child->firstChild !== null) {
                    $child->removeChild($child->firstChild);
                }

                continue;
            }

            if ($tag === 'video') {
                $src = trim($child->getAttribute('src'));

                // 视频只允许 http(s) 或本站相对地址；data: 内联视频体积大且难审计，直接丢弃。
                if (! $this->isSafeMediaUrl($src)) {
                    $node->removeChild($child);

                    continue;
                }

                $this->sanitizeAttributes($child, 'video');

                // <video> 里的 <source> 子标签不在白名单，统一清空，只保留 src 属性
                while ($child->firstChild !== null) {
                    $child->removeChild($child->firstChild);
                }

                continue;
            }

            if (in_array($tag, self::REMOVE_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->cleanChildren($child);
                $this->unwrap($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->cleanChildren($child);
        }
    }

    /**
     * 清洗内联 SVG 子树，返回 false 表示整棵丢弃。
     */
    private function sanitizeSvg(DOMElement $element): bool
    {
        return $this->sanitizeSvgNode($element, 0);
    }

    private function sanitizeSvgNode(DOMElement $element, int $depth): bool
    {
        if ($depth > self::SVG_MAX_DEPTH) {
            return false;
        }

        $name = strtolower($element->tagName);
        $allowed = self::SVG_ELEMENTS[$name] ?? null;

        if ($allowed === null) {
            return false;
        }

        // SMIL 动画的 attributeName 决定它改哪个属性：不在白名单里就整段丢弃，
        // 否则 `attributeName="href"` 能在运行时把安全属性改成脚本入口。
        $target = $this->svgAttribute($element, 'attributename');

        if ($target !== null && ! in_array(strtolower(trim($target)), self::SVG_ANIMATABLE_ATTRIBUTES, true)) {
            return false;
        }

        $this->sanitizeSvgAttributes($element, $name, $allowed);

        $children = [];

        foreach (HtmlDocument::childNodes($element) as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (! $this->sanitizeSvgNode($child, $depth + 1)) {
                    $element->removeChild($child);
                }

                continue;
            }

            // 只有文本类元素保留文本节点，其余位置的文本一律丢弃（避免把页面文字塞进动画卡片）
            if (! in_array($name, self::SVG_TEXT_ELEMENTS, true)) {
                $element->removeChild($child);
            }
        }

        return true;
    }

    /** 大小写不敏感地读 SVG 属性（libxml 会把属性名全部小写化）。 */
    private function svgAttribute(DOMElement $element, string $name): ?string
    {
        foreach ($element->attributes as $attribute) {
            if (strtolower($attribute->name) === $name) {
                return $attribute->value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function sanitizeSvgAttributes(DOMElement $element, string $name, array $allowed): void
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $key = strtolower($attribute->name);
            $value = $attribute->value;

            if ($key === SvgOpacityAnimation::ATTRIBUTE) {
                $animation = $name === 'g' ? SvgOpacityAnimation::normalize($value) : null;

                if ($animation === null) {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute(SvgOpacityAnimation::ATTRIBUTE, json_encode($animation, JSON_THROW_ON_ERROR));
                }

                continue;
            }

            // 媒体引用只允许挂在 SVG <image> 上（RemoteImageImporter 本地化后回写）
            if ($key === MediaReferences::ATTRIBUTE) {
                if ($name !== 'image' || preg_match('/^[1-9]\d{0,9}$/', trim($value)) !== 1) {
                    $element->removeAttribute($attribute->name);
                }

                continue;
            }

            if (! in_array($key, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($key === 'style') {
                $style = $this->sanitizeStyle($value);

                if ($style === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $style);
                }

                continue;
            }

            // SVG <image> 只允许 http(s) 与本站相对地址，挡掉 data:/javascript: 与内网探测
            if (($key === 'href' || $key === 'xlink:href') && ! $this->isSafeMediaUrl($value)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * iframe 是否来自白名单视频站。
     *
     * 只接受 https（含 //host 协议相对写法），host 必须命中配置清单（fnmatch 通配），
     * 拒绝 userinfo、非 443 端口、空白/控制字符与反斜杠。
     */
    private function isAllowedEmbed(DOMElement $element): bool
    {
        $src = trim($element->getAttribute('src'));

        if ($src === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $src) === 1) {
            return false;
        }

        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }

        $parts = parse_url($src);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === '') {
            return false;
        }

        // 白名单写 bilibili.com 时也要命中 www.bilibili.com：编辑器里粘的播放器地址
        // 常带 www.，只按原样匹配会导致放行判断与清洗结果不一致、内容被静默删掉。
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        foreach ($this->embedHosts() as $pattern) {
            if ($pattern !== '' && (fnmatch($pattern, $host) || fnmatch($pattern, $bare))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 允许的 iframe 域名清单，构造入参优先（便于测试），否则读配置。
     *
     * @return list<string>
     */
    private function embedHosts(): array
    {
        $hosts = $this->allowedEmbedHosts;

        if ($hosts === null) {
            // 纯单元测试里没有容器，读不到配置时按「不放开任何 iframe」处理
            $app = function_exists('app') ? app() : null;
            $hosts = $app !== null && $app->bound('config')
                ? (array) config('cmf-rich-content.embeds.allowed_hosts', [])
                : [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            $hosts,
        )));
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            // 内联事件、样式类名与 id 一律不保留（粘贴来源的 class 没有对应 CSS）
            if (str_starts_with($name, 'on') || $name === 'class' || $name === 'id') {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'style') {
                $style = $this->sanitizeStyle($value);

                if ($style === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $style);
                }

                continue;
            }

            if (! $this->attributeAllowed($tag, $name)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            // 视频封面同样只允许 http(s) / 相对地址，挡掉 http 外链追踪像素与非资源协议
            if ($name === 'poster' && ! $this->isSafeMediaUrl($value)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if (($name === 'href' && ! $this->isSafeUrl($value, false))
                || ($name === 'src' && ! $this->isSafeUrl($value, true))) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            // 媒体引用只挂在图片与上传视频上；其它标签上的同名属性视为第三方残留
            if ($name === MediaReferences::ATTRIBUTE
                && (! in_array($tag, ['img', 'video'], true) || preg_match('/^[1-9]\d{0,9}$/', trim($value)) !== 1)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if (in_array($name, self::INTEGER_ATTRIBUTES, true) && preg_match('/^\d{1,5}$/', trim($value)) !== 1) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'target' && ! in_array(strtolower(trim($value)), ['_blank', '_self'], true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'rel' && preg_match('/^[a-z\s]+$/i', trim($value)) !== 1) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    private function attributeAllowed(string $tag, string $name): bool
    {
        if (in_array($name, self::GLOBAL_ATTRIBUTES, true)) {
            return true;
        }

        if (str_starts_with($name, 'data-')) {
            return in_array($name, self::ALLOWED_DATA_ATTRIBUTES, true);
        }

        return in_array($name, self::TAG_ATTRIBUTES[$tag] ?? [], true);
    }

    private function sanitizeStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            // 在 trim 前检查，避免边缘控制字符被悄悄归一化为合法值。
            if (preg_match('/\p{C}/u', $declaration) !== 0) {
                continue;
            }

            $declaration = trim($declaration);

            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if (! in_array($property, self::ALLOWED_STYLES, true) || ! $this->isSafeStyleValue($property, $value)) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        return implode('; ', $kept);
    }

    /**
     * CSS 不是脚本语言的替代入口：只接受可枚举的静态值语法，不尝试解码混淆。
     */
    private function isSafeStyleValue(string $property, string $value): bool
    {
        if ($value === '' || preg_match('/[\p{C}<>&{}\\\\]/u', $value) !== 0
            || str_contains($value, '/*') || str_contains($value, '*/')
            || preg_match('/\b(?:url|expression|javascript|vbscript|behavior|binding|var|env|attr)\b/i', $value) === 1) {
            return false;
        }

        $value = preg_replace('/ *! *important$/iD', '', $value) ?? '';

        if ($property === 'font-family') {
            foreach (explode(',', $value) as $family) {
                if (preg_match('/^(?:"[\p{L}\p{N} _-]+"|\'[\p{L}\p{N} _-]+\'|[\p{L}\p{N} _-]+)$/uD', trim($family)) !== 1) {
                    return false;
                }
            }

            return true;
        }

        if (preg_match('/^[#a-z0-9(),.% \-+*\/]+$/iD', $value) !== 1) {
            return false;
        }

        $allowedFunctions = self::ALLOWED_STYLE_FUNCTIONS;

        if ($property === 'background') {
            if (! $this->isSafeBackground($value)) {
                return false;
            }

            $allowedFunctions = [...$allowedFunctions, ...self::GRADIENT_FUNCTIONS];
        } elseif ($property === 'transform') {
            $allowedFunctions = [...$allowedFunctions, ...self::TRANSFORM_FUNCTIONS];
        }

        preg_match_all('/([a-z][a-z0-9-]*) *\(|[()]/i', $value, $tokens, PREG_SET_ORDER);
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token[0] === ')') {
                if (--$depth < 0) {
                    return false;
                }

                // 背景只允许一个顶层颜色 / 渐变，避免简写夹带 fixed 等附件行为。
                if ($property === 'background' && $depth === 0 && $index !== array_key_last($tokens)) {
                    return false;
                }
            } elseif (! isset($token[1]) || ! in_array(strtolower($token[1]), $allowedFunctions, true)) {
                return false;
            } else {
                $depth++;
            }
        }

        return $depth === 0;
    }

    /**
     * background 简写不得引入图片、定位或附件行为，只保留纯色 / CSS 渐变。
     */
    private function isSafeBackground(string $value): bool
    {
        if (in_array(strtolower($value), self::BACKGROUND_COLOR_KEYWORDS, true)
            || preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value) === 1) {
            return true;
        }

        return preg_match('/^(?:rgba?|hsla?|(?:repeating-)?(?:linear|radial)-gradient)\(/i', $value) === 1
            && str_ends_with($value, ')');
    }

    /**
     * 媒体资源地址（视频 src / 封面 poster）：只允许 http(s)、协议相对与站内相对路径。
     *
     * 与 isSafeUrl 的差别是不接受 mailto:/tel: 等非资源协议——拼进 <video src>
     * 只会变成一次无效请求。
     */
    private function isSafeMediaUrl(string $url): bool
    {
        return $this->isSafeUrl($url, false)
            && preg_match('/^(mailto|tel):/i', trim($url)) !== 1;
    }

    private function isSafeUrl(string $url, bool $allowInlineImage): bool
    {
        $url = trim($url);

        // 浏览器会在解析协议时忽略 tab / 换行等字符；必须在协议白名单前拒绝，
        // 否则 java&#x09;script: 经 DOM 解码后会被误判成普通相对地址。
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $lower = strtolower($url);

        foreach (['javascript:', 'vbscript:', 'file:', 'blob:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return false;
            }
        }

        if (str_starts_with($lower, 'data:')) {
            return $allowInlineImage
                && preg_match('#^data:image/(png|jpe?g|gif|webp|bmp|avif);base64,[a-z0-9+/=\s]+$#i', $url) === 1;
        }

        if (preg_match('#^(https?:)?//#i', $url) === 1) {
            return true;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) === 1) {
            return preg_match('/^(mailto|tel):/i', $url) === 1;
        }

        return true;
    }
}
