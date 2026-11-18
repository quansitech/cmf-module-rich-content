<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\RichContent\Support\HtmlDocument;
use Quansitech\Cmf\RichContent\Support\HtmlSanitizer;
use Quansitech\Cmf\RichContent\Support\SvgCardNormalizer;

/**
 * 富文本保存前清洗的白名单行为。
 *
 * 这里覆盖两类风险：粘贴进来就能落库的脚本/事件属性，
 * 以及编辑器（TinyMCE）生成、但清洗器不该顺手删掉的图片排版信息。
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_media_reference_attribute_only_keeps_positive_integers(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p><img src="/cmf-media/a.png" data-cmf-media="12"></p>'
            .'<p><img src="/cmf-media/b.png" data-cmf-media="javascript:alert(1)"></p>'
            .'<p><img src="/cmf-media/c.png" data-cmf-media="012"></p>'
            .'<p><span data-cmf-media="7">文字</span></p>',
        );

        $this->assertStringContainsString('data-cmf-media="12"', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('data-cmf-media="012"', $clean);
        $this->assertStringNotContainsString('data-cmf-media="7"', $clean, '该属性只允许挂在 img 上');
    }

    public function test_dangerous_tags_are_removed_together_with_their_content(): void
    {
        $html = '<p>正文</p><script>alert(1)</script><iframe src="https://evil.test/x"></iframe><p>结尾</p>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<p>正文</p>', $clean);
        $this->assertStringContainsString('<p>结尾</p>', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('evil.test', $clean);
    }

    public function test_event_handlers_and_javascript_urls_are_stripped(): void
    {
        $html = '<p onclick="steal()">文字 <a href="javascript:alert(1)">链接</a></p>'
            .'<img src="https://cdn.example.com/a.png" onerror="steal()">';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('href=', $clean);
        $this->assertStringContainsString('链接', $clean);
        $this->assertStringContainsString('https://cdn.example.com/a.png', $clean);
    }

    #[DataProvider('obfuscatedUrls')]
    public function test_obfuscated_script_protocols_are_removed(string $url): void
    {
        $clean = $this->sanitizer->sanitize('<p><a href="'.$url.'">链接</a><img src="'.$url.'"></p>');
        $this->assertStringNotContainsString('href=', $clean);
        $this->assertStringNotContainsString('src=', $clean);
        $this->assertStringContainsString('链接', $clean);
    }

    public static function obfuscatedUrls(): array
    {
        return [
            ['java&#x09;script:alert(1)'], ['java&#10;script:alert(1)'], ['java&#13;script:alert(1)'],
            ['java&#32;script:alert(1)'], ['vb&#9;script:msgbox(1)'], ['https:\\evil.example/x'],
        ];
    }

    public function test_head_only_markup_never_bypasses_sanitizing(): void
    {
        foreach (['<head><script>alert(1)</script></head>', '<script>alert(1)</script>', '<head><style>evil</style></head>'] as $html) {
            $this->assertSame('', $this->sanitizer->sanitize($html));
        }
    }

    public function test_image_keeps_resize_and_style_information_used_by_the_editor(): void
    {
        $html = '<img src="/cmf-media/t/editor/a.png" alt="配图" data-id="12" data-cmf-media="12" data-src="https://example.com/a.png"'
            .' width="320" height="180" style="width: 320px; height: auto; float: left; position: absolute; background-image: url(x)" class="align-left" id="pic">';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('src="/cmf-media/t/editor/a.png"', $clean);
        $this->assertStringContainsString('data-cmf-media="12"', $clean);
        $this->assertStringContainsString('width="320"', $clean);
        $this->assertStringContainsString('height="180"', $clean);
        $this->assertStringContainsString('width: 320px', $clean);
        $this->assertStringContainsString('height: auto', $clean);
        $this->assertStringContainsString('float: left', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringNotContainsString('background-image', $clean);
        $this->assertStringNotContainsString('align-left', $clean);
        $this->assertStringNotContainsString('id="pic"', $clean);
    }

    public function test_wechat_recommendation_keeps_its_horizontal_circular_number_layout(): void
    {
        $html = '<section style="display:inline-block"><section style="display:flex;justify-content:flex-start">'
            .'<section style="width:25px;height:25px;background:rgb(14,124,226);border-radius:100%;line-height:24px">1</section>'
            .'<section>往期推荐</section></section></section>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertSame(
            '<section style="display: inline-block"><section style="display: flex; justify-content: flex-start">'
            .'<section style="width: 25px; height: 25px; background: rgb(14,124,226); border-radius: 100%; line-height: 24px">1</section>'
            .'<section>往期推荐</section></section></section>',
            $clean,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeStyles(): array
    {
        return [
            'box dimensions' => ['box-sizing: border-box; min-width: 25px; min-height: 25px; max-width: 100%; max-height: 200px'],
            'flex sizing and flow' => ['flex: 1 0 25px; flex-flow: row nowrap; flex-direction: row; flex-wrap: wrap; flex-grow: 1; flex-shrink: 0; flex-basis: auto'],
            'alignment and gaps' => ['align-items: center; align-self: flex-start; align-content: space-between; justify-content: flex-start; gap: 10px; row-gap: 4px; column-gap: 6px'],
            'border edges' => ['border-top: 1px solid #abc; border-right: 2px dashed red; border-bottom: 3px dotted #def; border-left: 4px double blue'],
            'border edge colors' => ['border-top-color: red; border-right-color: blue; border-bottom-color: #abc; border-left-color: rgb(1,2,3)'],
            'border edge widths' => ['border-top-width: 1px; border-right-width: 2px; border-bottom-width: 3px; border-left-width: 4px'],
            'border edge styles' => ['border-top-style: solid; border-right-style: dashed; border-bottom-style: dotted; border-left-style: double'],
            'border corners and spacing' => ['border-top-left-radius: 2px; border-top-right-radius: 3px; border-bottom-left-radius: 4px; border-bottom-right-radius: 5px; border-spacing: 2px'],
            'quoted Chinese fonts and important' => ['font-family: "微软雅黑", \'宋体\', PingFang SC, sans-serif; font-size: 16px !important; color: #123 ! IMPORTANT'],
            'spacing and decoration' => ['word-spacing: 2px; letter-spacing: 1px; text-decoration-line: underline; text-decoration-style: wavy; text-decoration-color: #123; text-decoration-thickness: 2px; text-underline-offset: 3px'],
            'nested static math' => ['width: calc(100% - clamp(20px, 2vw, 60px)); min-width: min(100%, 25px); max-width: max(25px, 50%)'],
            'modern color syntax' => ['color: rgb(14 124 226 / 90%); background-color: hsla(210, 90%, 50%, 0.8)'],
            'static transforms and overflow' => ['transform: translateX(2px) rotate(-5deg) scale(1.1); transform-origin: 50% 50%; overflow: hidden; overflow-x: clip; overflow-y: auto'],
            'hex background' => ['background: #0e7ce2 !important'],
            'named color background' => ['background: rebeccapurple'],
            'linear gradient background' => ['background: linear-gradient(90deg, rgb(14,124,226) 0%, #fff 100%)'],
            'radial gradient background' => ['background: radial-gradient(circle at center, rgba(14,124,226,0.8), transparent)'],
            'repeating gradient background' => ['background: repeating-linear-gradient(45deg, #fff 0px, #eee 10px)'],
        ];
    }

    #[DataProvider('safeStyles')]
    public function test_safe_static_styles_survive_sanitizing(string $style): void
    {
        $html = '<p style="'.htmlspecialchars($style, ENT_QUOTES, 'UTF-8').'">正文</p>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertSame($style, HtmlDocument::load($clean)->getElementsByTagName('p')->item(0)?->getAttribute('style'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeStyles(): array
    {
        return [
            'URL background' => ['background: url(https://evil.test/a.png)'],
            'mixed case URL' => ['background: UrL(https://evil.test/a.png)'],
            'URL in a gradient' => ['background: linear-gradient(red, url(https://evil.test/a.png))'],
            'expression' => ['width: expression(alert(1))'],
            'mixed case expression' => ['width: ExPrEsSiOn(alert(1))'],
            'comment-obfuscated expression' => ['width: ex/**/pression(alert(1))'],
            'comment-obfuscated URL' => ['background: u/**/rl(https://evil.test/a.png)'],
            'comment-obfuscated property' => ['co/**/lor: red'],
            'unclosed CSS comment' => ['width: 25px/*'],
            'CSS comment terminator' => ['width: 25px*/'],
            'escaped expression' => ['width: e\\78pression(alert(1))'],
            'escaped URL' => ['background: \\75rl(https://evil.test/a.png)'],
            'javascript token' => ['color: javascript'],
            'vbscript token' => ['color: vbscript'],
            'behavior token' => ['width: behavior(test)'],
            'binding token' => ['width: -moz-binding(test)'],
            'custom property' => ['--measure: 25px'],
            'CSS variable' => ['width: var(--measure)'],
            'nested CSS variable' => ['width: calc(100% - var(--measure))'],
            'environment function' => ['padding-top: env(safe-area-inset-top)'],
            'attribute function' => ['width: attr(data-size px)'],
            'unknown resource function' => ['background: image-set("https://evil.test/a.png" 1x)'],
            'unknown color function' => ['color: light-dark(red, blue)'],
            'unbalanced function' => ['width: calc(100% - 2px'],
            'extra closing parenthesis' => ['width: 25px)'],
            'bare parenthesis' => ['width: (25px)'],
            'quoted dimension' => ['width: "25px"'],
            'unclosed font quote' => ['font-family: "微软雅黑'],
            'invalid important' => ['font-size: 16px !urgent'],
            'duplicate important' => ['font-size: 16px !important !important'],
            'HTML characters' => ['font-family: "<svg onload=steal>"'],
            'HTML ampersand' => ['font-family: "A&B"'],
            'ASCII control' => ["width: 2\t5px"],
            'Unicode control' => ["width: 2\u{202E}5px"],
            'fixed positioning' => ['position: fixed'],
            'stacking order' => ['z-index: 99999'],
            'background image property' => ['background-image: linear-gradient(red, blue)'],
            'non-color background keyword' => ['background: fixed'],
            'background positioning shorthand' => ['background: red center / cover'],
            'gradient background attachment' => ['background: linear-gradient(red, blue) fixed, linear-gradient(blue, red)'],
        ];
    }

    #[DataProvider('unsafeStyles')]
    public function test_unsafe_style_declarations_are_dropped_without_losing_safe_neighbors(string $unsafeStyle): void
    {
        $style = 'color: #123456; '.$unsafeStyle.'; margin-top: 12px';
        $html = '<p style="'.htmlspecialchars($style, ENT_QUOTES, 'UTF-8').'">正文</p>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertSame('<p style="color: #123456; margin-top: 12px">正文</p>', $clean);
    }

    public function test_style_attribute_is_removed_when_no_safe_declaration_remains(): void
    {
        $clean = $this->sanitizer->sanitize('<p style="width: expression(alert(1)); background: url(x)">正文</p>');

        $this->assertSame('<p>正文</p>', $clean);
    }

    public function test_unknown_tags_are_unwrapped_but_text_is_kept(): void
    {
        $clean = $this->sanitizer->sanitize('<p><u>下划线</u> <font color="red">红字</font></p>');

        $this->assertStringContainsString('<u>下划线</u>', $clean);
        $this->assertStringContainsString('红字', $clean);
        $this->assertStringNotContainsString('<font', $clean);
        $this->assertStringNotContainsString('color=', $clean);
    }

    public function test_table_and_list_structure_survive(): void
    {
        $html = '<table><thead><tr><th colspan="2">表头</th></tr></thead>'
            .'<tbody><tr><td>1</td><td>2</td></tr></tbody></table><ul><li>一项</li></ul>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('colspan="2"', $clean);
        $this->assertStringContainsString('<td>1</td>', $clean);
        $this->assertStringContainsString('<li>一项</li>', $clean);
    }

    public function test_plain_text_is_wrapped_in_a_paragraph_and_escaped(): void
    {
        $clean = $this->sanitizer->sanitize('普通文字 < 不是标签');

        $this->assertSame('<p>普通文字 &lt; 不是标签</p>', $clean);
    }

    /**
     * @return list<string>
     */
    private function videoHosts(): array
    {
        return [
            'player.bilibili.com',
            'player.youku.com',
            'player.iqiyi.com',
            'open.iqiyi.com',
            'v.qq.com',
            'channels.weixin.qq.com',
            'mp.weixin.qq.com',
            'open.douyin.com',
            'www.douyin.com',
            'www.youtube.com',
            'youtube.com',
            'm.youtube.com',
            'www.youtube-nocookie.com',
        ];
    }

    public function test_whitelisted_video_iframe_is_kept_with_only_safe_attributes(): void
    {
        $sanitizer = new HtmlSanitizer($this->videoHosts());

        $clean = $sanitizer->sanitize(
            '<p>前</p><iframe src="https://player.bilibili.com/player.html?aid=1&amp;p=1"'
            .' width="800" height="450" frameborder="0" allowfullscreen="true"'
            .' sandbox="allow-scripts allow-same-origin" onload="alert(1)" style="position:fixed"></iframe><p>后</p>'
        );

        $this->assertStringContainsString('<iframe', $clean);
        $this->assertStringContainsString('src="https://player.bilibili.com/player.html?aid=1&amp;p=1"', $clean);
        $this->assertStringContainsString('width="800"', $clean);
        $this->assertStringContainsString('height="450"', $clean);
        $this->assertStringContainsString('allowfullscreen="true"', $clean);
        $this->assertStringNotContainsString('sandbox', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringContainsString('前', $clean);
        $this->assertStringContainsString('后', $clean);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untrustedIframeProvider(): array
    {
        return [
            '其他域名' => ['<iframe src="https://evil.example.com/x"></iframe>'],
            '白名单域名但 http' => ['<iframe src="http://player.bilibili.com/player.html?aid=1"></iframe>'],
            'javascript 协议' => ['<iframe src="javascript:alert(1)"></iframe>'],
            'data 协议' => ['<iframe src="data:text/html,<script>alert(1)</script>"></iframe>'],
            '带账号密码' => ['<iframe src="https://user:pw@player.bilibili.com/x"></iframe>'],
            '非 443 端口' => ['<iframe src="https://player.bilibili.com:8080/x"></iframe>'],
            '子域伪装' => ['<iframe src="https://player.bilibili.com.evil.example/x"></iframe>'],
        ];
    }

    #[DataProvider('untrustedIframeProvider')]
    public function test_untrusted_iframes_are_removed_together_with_their_content(string $html): void
    {
        $sanitizer = new HtmlSanitizer($this->videoHosts());

        $this->assertStringNotContainsString('<iframe', $sanitizer->sanitize($html));
    }

    public function test_protocol_relative_iframe_src_is_normalized_to_https(): void
    {
        $sanitizer = new HtmlSanitizer($this->videoHosts());

        $clean = $sanitizer->sanitize('<iframe src="//player.bilibili.com/player.html?aid=1"></iframe>');

        $this->assertStringContainsString('src="https://player.bilibili.com/player.html?aid=1"', $clean);
    }

    public function test_iframe_whitelist_only_allows_configured_hosts(): void
    {
        $sanitizer = new HtmlSanitizer(['v.qq.com']);

        $this->assertStringContainsString(
            '<iframe',
            $sanitizer->sanitize('<iframe src="https://v.qq.com/txp/iframe/player.html?vid=1"></iframe>'),
        );
        $this->assertStringNotContainsString(
            '<iframe',
            $sanitizer->sanitize('<iframe src="https://player.bilibili.com/player.html?aid=1"></iframe>'),
        );
    }

    public function test_iframe_is_dropped_when_no_host_is_configured(): void
    {
        $sanitizer = new HtmlSanitizer([]);

        $this->assertStringNotContainsString(
            '<iframe',
            $sanitizer->sanitize('<iframe src="https://player.bilibili.com/player.html?aid=1"></iframe>'),
        );
    }

    public function test_uploaded_video_keeps_controls_size_and_media_reference(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p><video src="/cmf-media/video/a.mp4" controls="controls" width="640" height="360"'
            .' data-cmf-media="9" style="max-width:100%" onerror="alert(1)">'
            .'<source src="https://evil.example/x.mp4"></video></p>',
        );

        $this->assertStringContainsString('<video', $clean);
        $this->assertStringContainsString('src="/cmf-media/video/a.mp4"', $clean);
        $this->assertStringContainsString('controls="controls"', $clean);
        $this->assertStringContainsString('width="640"', $clean);
        $this->assertStringContainsString('data-cmf-media="9"', $clean);
        $this->assertStringContainsString('max-width: 100%', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        // <source> 不在白名单：只保留 video 自身的 src，避免绕过地址校验
        $this->assertStringNotContainsString('<source', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
    }

    public function test_video_poster_only_keeps_media_urls(): void
    {
        $kept = $this->sanitizer->sanitize(
            '<p><video src="/cmf-media/video/a.mp4" poster="/cmf-media/video/a.jpg"></video></p>',
        );
        $this->assertStringContainsString('poster="/cmf-media/video/a.jpg"', $kept);

        // mailto: 之类非资源协议拼进 poster 只会变成一次无效请求
        $dropped = $this->sanitizer->sanitize(
            '<p><video src="/cmf-media/video/a.mp4" poster="mailto:pass"></video></p>',
        );
        $this->assertStringNotContainsString('poster=', $dropped);
        $this->assertStringContainsString('<video', $dropped);
    }

    public function test_inline_svg_animation_card_is_kept(): void
    {
        $html = '<p>前</p><svg viewBox="0 0 1080 810"><g><image href="/cmf-media/a.png" data-cmf-media="12" x="0" y="0" width="1080" height="810"></image>'
            .'<animateTransform type="translate" dur="4s" values="1080 0;0 0" attributeName="transform" repeatCount="indefinite"></animateTransform></g></svg><p>后</p>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<svg', $clean);
        $this->assertStringContainsString('viewbox="0 0 1080 810"', strtolower($clean));
        $this->assertStringContainsString('<image', $clean);
        $this->assertStringContainsString('data-cmf-media="12"', $clean);
        $this->assertStringContainsString('animatetransform', strtolower($clean));
    }

    public function test_svg_drops_scripts_foreign_objects_and_unsafe_animation_targets(): void
    {
        $html = '<svg viewBox="0 0 10 10">'
            .'<script>alert(1)</script>'
            .'<foreignObject><img src="x" onerror="alert(1)"></foreignObject>'
            .'<animate attributeName="href" values="javascript:alert(1)"></animate>'
            .'<image href="javascript:alert(1)"></image>'
            .'<rect width="10" height="10" fill="#000"></rect>'
            .'</svg>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsStringIgnoringCase('script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('foreignobject', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
        $this->assertStringContainsString('<rect', $clean);
    }

    public function test_video_with_unsafe_source_is_removed_together_with_its_content(): void
    {
        foreach ([
            '<p><video src="javascript:alert(1)"></video></p>',
            '<p><video src="data:video/mp4;base64,AAAA"></video></p>',
            '<p><video src=""></video></p>',
            '<p><video src="mailto:pass"></video></p>',
            '<p><video src="tel:+8613800000000"></video></p>',
        ] as $html) {
            $this->assertStringNotContainsString('<video', $this->sanitizer->sanitize($html));
        }
    }

    public function test_opacity_metadata_survives_sanitizing_after_the_editor_removes_animate(): void
    {
        $html = '<svg><g><animate attributeName="opacity" values="0;1" dur="2000ms" repeatCount="indefinite"></animate></g></svg>';
        $normalized = (new SvgCardNormalizer)->normalize($html);
        $withoutAnimate = preg_replace('/<animate\b[^>]*>.*?<\/animate>/i', '', $normalized);

        $clean = $this->sanitizer->sanitize($withoutAnimate);

        $this->assertSame('{"attributeName":"opacity","values":"0;1","dur":"2s","begin":"0s","repeatCount":"indefinite"}', HtmlDocument::load($clean)->getElementsByTagName('g')->item(0)->getAttribute('data-cmf-svg-opacity'));
        $this->assertSame($clean, $this->sanitizer->sanitize($clean));
        $this->assertSame(1, HtmlDocument::load($this->sanitizer->sanitize($normalized))->getElementsByTagName('animate')->length);
    }

    public function test_resized_svg_keeps_dimensions_viewbox_and_animation_metadata(): void
    {
        $html = (new SvgCardNormalizer)->normalize('<svg width="480" height="270" viewBox="0 0 640 360" style="width:480px;height:auto;max-width:100%"><g><animate attributeName="opacity" values="0;1" dur="2s" repeatCount="indefinite"></animate><rect width="640" height="360" fill="#f60"></rect></g></svg>');

        $clean = $this->sanitizer->sanitize($html);
        $document = HtmlDocument::load($clean);
        $svg = $document->getElementsByTagName('svg')->item(0);

        $this->assertSame('480', $svg->getAttribute('width'));
        $this->assertSame('270', $svg->getAttribute('height'));
        $this->assertSame('0 0 640 360', $svg->getAttribute('viewbox'));
        $this->assertStringContainsString('480px', $svg->getAttribute('style'));
        $this->assertSame(1, $document->getElementsByTagName('animate')->length);
        $this->assertStringContainsString('"attributeName":"opacity"', $document->getElementsByTagName('g')->item(0)->getAttribute('data-cmf-svg-opacity'));
    }

    public function test_svg_opacity_metadata_is_validated_and_only_allowed_on_svg_groups(): void
    {
        foreach (['arbitrary', '{"attributeName":"href","values":"0;1","dur":"1s"}', '{"attributeName":"opacity","values":"0;1","dur":"1s","onbegin":"alert(1)"}', '{"attributeName":"opacity","values":"0;1","dur":"1s","begin":"click"}'] as $metadata) {
            $html = '<svg><g data-cmf-svg-opacity="'.htmlspecialchars($metadata, ENT_QUOTES, 'UTF-8').'"></g></svg>';

            $this->assertStringNotContainsString('data-cmf-svg-opacity', $this->sanitizer->sanitize($html));
        }

        $metadata = htmlspecialchars('{"dur":"1000ms","values":"0;1","attributeName":"opacity"}', ENT_QUOTES, 'UTF-8');
        $html = '<p data-cmf-svg-opacity="'.$metadata.'">text</p><svg data-cmf-svg-opacity="'.$metadata.'"><rect data-cmf-svg-opacity="'.$metadata.'"></rect><g data-cmf-svg-opacity="'.$metadata.'"></g></svg>';

        $clean = $this->sanitizer->sanitize($html);

        $this->assertSame(1, substr_count($clean, 'data-cmf-svg-opacity'));
        $this->assertSame('{"attributeName":"opacity","values":"0;1","dur":"1s","begin":"0s"}', HtmlDocument::load($clean)->getElementsByTagName('g')->item(0)->getAttribute('data-cmf-svg-opacity'));
    }
}
