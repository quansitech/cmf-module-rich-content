<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Feature;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\RichContent\Support\HtmlDocument;
use Quansitech\Cmf\RichContent\Support\HtmlSanitizer;
use Quansitech\Cmf\RichContent\Support\RemoteImageGuard;
use Quansitech\Cmf\RichContent\Support\RemoteImageImporter;
use Quansitech\Cmf\RichContent\Support\SvgCardNormalizer;
use Quansitech\Cmf\RichContent\Support\WechatArticleFetcher;
use Quansitech\Cmf\RichContent\Tests\TestCase;

/**
 * 公众号正文提取与链接准入（不依赖真实网络）。
 */
class WechatArticleFetcherTest extends TestCase
{
    /**
     * @param  list<string>  $resolvedIps
     */
    private function fetcher(?Closure $transport = null, array $resolvedIps = ['93.184.216.34']): WechatArticleFetcher
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => $resolvedIps);

        return new WechatArticleFetcher(
            $guard,
            new HtmlSanitizer,
            new RemoteImageImporter($guard, new MediaUploader),
            new SvgCardNormalizer,
            $transport,
        );
    }

    public function test_extracts_js_content_and_promotes_lazy_loaded_images(): void
    {
        $html = <<<'HTML'
        <html><body>
            <div class="rich_media_area_primary">
                <h1 id="activity-name">标题</h1>
                <div id="js_content">
                    <p style="color: #333; line-height: 1.8">正文第一段</p>
                    <img class="img_loading" data-src="https://mmbiz.qpic.cn/a.png" src="data:image/gif;base64,R0lGOD">
                </div>
            </div>
            <script>alert(1)</script>
        </body></html>
        HTML;

        $body = $this->fetcher()->extractBody($html);

        $this->assertStringContainsString('正文第一段', $body);
        $this->assertStringContainsString('src="https://mmbiz.qpic.cn/a.png"', $body);
        $this->assertStringNotContainsString('data-src', $body);
        $this->assertStringNotContainsString('img_loading', $body);
        $this->assertStringNotContainsString('alert(1)', $body, '页面脚本不进入正文');
        $this->assertStringNotContainsString('标题', $body, '只取正文容器，不取页面标题');
    }

    public function test_extracted_body_keeps_image_layout_and_carries_page_style_defaults(): void
    {
        $html = '<div id="js_content"><p>正文</p><a href="https://example.com">默认链接</a>'
            .'<a href="https://example.com/custom" style="color: #ff0000">自定链接</a>'
            .'<img class="img_loading" data-src="https://mmbiz.qpic.cn/a.png" style="width: 320px; border-radius: 8px"></div>';

        $body = $this->fetcher()->extractBody($html);
        $document = HtmlDocument::load($body);
        $image = $document->getElementsByTagName('img')->item(0);
        $links = $document->getElementsByTagName('a');

        $this->assertStringContainsString('data-type="wechat-article"', $body);
        $this->assertStringContainsString('font-size: 17px', $body);
        $this->assertStringContainsString('width: 320px', $image->getAttribute('style'));
        $this->assertStringContainsString('border-radius: 8px', $image->getAttribute('style'));
        $this->assertSame('https://mmbiz.qpic.cn/a.png', $image->getAttribute('src'));
        $this->assertStringContainsString('color: #576b95; text-decoration: none', $links->item(0)->getAttribute('style'));
        $this->assertStringEndsWith('color: #ff0000', $links->item(1)->getAttribute('style'));
    }

    public function test_falls_back_to_rich_media_content_wrapper(): void
    {
        $html = '<div class="rich_media_content js_underline_content"><p>备用正文</p></div>';

        $this->assertStringContainsString('备用正文', $this->fetcher()->extractBody($html));
    }

    public function test_excludes_page_chrome_and_keeps_the_authors_in_article_content(): void
    {
        $html = <<<'HTML'
        <div class="rich_media_content">
            <h1 id="activity-name">外部页面标题</h1>
            <div id="meta_content"><span id="js_name">页面账号信息</span><span id="publish_time">页面发布时间</span></div>
            <div id="js_content">
                <h1>正文自己的标题</h1>
                <p>正文关注引导与二维码</p>
                <p>实际正文</p>
                <p>正文往期推荐</p>
            </div>
            <div id="js_toobar3">页面阅读点赞</div>
        </div>
        HTML;

        $body = $this->fetcher()->extractBody($html);

        foreach (['正文自己的标题', '正文关注引导与二维码', '实际正文', '正文往期推荐'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        foreach (['外部页面标题', '页面账号信息', '页面发布时间', '页面阅读点赞'] as $text) {
            $this->assertStringNotContainsString($text, $body);
        }
    }

    public function test_extracts_a_text_only_body_without_its_page_shell(): void
    {
        $body = $this->fetcher()->extractBody('<h1 id="activity-name">外部标题</h1>'
            .'<div id="js_content">正文只有纯文字 &amp; 说明</div><div id="js_toobar3">外部尾部</div>');

        $this->assertStringContainsString('正文只有纯文字 &amp; 说明', $body);
        $this->assertStringNotContainsString('外部标题', $body);
        $this->assertStringNotContainsString('外部尾部', $body);
    }

    #[DataProvider('unreliableArticleContainers')]
    public function test_rejects_ambiguous_or_page_sized_body_containers(string $html): void
    {
        $this->assertSame('', $this->fetcher()->extractBody($html));
    }

    /** @return array<string, array{string}> */
    public static function unreliableArticleContainers(): array
    {
        return [
            'class wrapper containing page metadata' => ['<div class="rich_media_content"><h1 id="activity-name">标题</h1><p>正文</p><div id="js_toobar3">尾部</div></div>'],
            'class wrapper containing page chrome classes' => ['<div class="rich_media_content"><div class="rich_media_meta_list">元信息</div><p>正文</p></div>'],
            'two class candidates' => ['<div class="rich_media_content">第一份</div><div class="rich_media_content">第二份</div>'],
            'duplicate article ids' => ['<div id="js_content">第一份</div><div id="js_content">第二份</div>'],
            'whole body candidate' => ['<html><body class="rich_media_content"><h1>标题</h1><p>正文</p><footer>尾部</footer></body></html>'],
            'id candidate containing a page toolbar' => ['<div id="js_content"><p>正文</p><div id="js_toobar3">尾部</div></div>'],
        ];
    }

    public function test_returns_empty_when_page_has_no_article_body(): void
    {
        $this->assertSame('', $this->fetcher()->extractBody('<html><body><p>环境异常</p></body></html>'));
    }

    public function test_rejects_urls_outside_the_wechat_allow_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('只支持微信公众号文章链接');

        $this->fetcher()->fetch('https://example.com/s/abcdef');
    }

    public function test_rejects_wechat_link_that_resolves_to_a_private_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('解析不到公网地址');

        $this->fetcher(resolvedIps: ['10.0.0.8'])->fetch('https://mp.weixin.qq.com/s/abcdef');
    }

    public function test_rejects_link_with_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fetcher()->fetch('https://user:secret@mp.weixin.qq.com/s/abcdef');
    }

    public function test_fetches_body_through_the_transport(): void
    {
        $transport = static fn (array $target): array => [
            'status' => 200,
            'location' => null,
            'body' => '<html><body><div id="js_content"><p>从公众号抓来的正文</p></div></body></html>',
        ];

        $result = $this->fetcher($transport)->fetch('https://mp.weixin.qq.com/s/abcdef');

        $this->assertStringContainsString('从公众号抓来的正文', $result->html);
        $this->assertFalse($result->changed());
    }

    public function test_empty_body_from_wechat_page_raises_a_readable_error(): void
    {
        $transport = static fn (array $target): array => [
            'status' => 200,
            'location' => null,
            'body' => '<html><body><p>当前环境异常，完成验证后即可继续访问</p></body></html>',
        ];

        $this->expectExceptionMessage('没有抓到公众号正文');

        $this->fetcher($transport)->fetch('https://mp.weixin.qq.com/s/abcdef');
    }

    public function test_http_error_status_raises_a_readable_error(): void
    {
        $transport = static fn (array $target): array => ['status' => 404, 'location' => null, 'body' => ''];

        $this->expectExceptionMessage('HTTP 404');

        $this->fetcher($transport)->fetch('https://mp.weixin.qq.com/s/abcdef');
    }
}
