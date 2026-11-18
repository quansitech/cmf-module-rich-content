<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\RichContent\Support\HtmlDocument;
use Quansitech\Cmf\RichContent\Support\SvgCardNormalizer;

/**
 * 公众号 SVG 动画卡片的形态归一：foreignObject + 背景图 → 语义等价的 <image href>。
 */
final class SvgCardNormalizerTest extends TestCase
{
    public function test_foreign_object_background_image_becomes_an_svg_image(): void
    {
        $html = '<svg viewBox="0 0 1080 810"><g>'
            .'<foreignObject width="100%" height="100%"><svg style="width: 100%;background-image: url(&quot;https://mmbiz.qpic.cn/a.jpg&quot;)"></svg></foreignObject>'
            .'<animateTransform type="translate" dur="4s" values="1080 0;0 0" attributeName="transform" repeatCount="indefinite"></animateTransform>'
            .'</g></svg>';

        $normalized = (new SvgCardNormalizer)->normalize($html);

        $this->assertStringNotContainsStringIgnoringCase('foreignobject', $normalized);
        $this->assertStringContainsString('<image', $normalized);
        $this->assertStringContainsString('href="https://mmbiz.qpic.cn/a.jpg"', $normalized);
        // 尺寸取自外层 svg 的 viewBox，动画结构保持不变
        $this->assertStringContainsString('width="1080"', $normalized);
        $this->assertStringContainsString('height="810"', $normalized);
        // libxml 会把标签名小写化，浏览器解析时再还原成 animateTransform
        $this->assertStringContainsStringIgnoringCase('animatetransform', $normalized);
    }

    public function test_foreign_object_without_a_background_image_is_dropped(): void
    {
        $html = '<svg viewBox="0 0 10 10"><g><foreignObject><p>文字</p></foreignObject><rect width="10" height="10"></rect></g></svg>';

        $normalized = (new SvgCardNormalizer)->normalize($html);

        $this->assertStringNotContainsStringIgnoringCase('foreignobject', $normalized);
        $this->assertStringNotContainsString('<image', $normalized);
        $this->assertStringContainsString('<rect', $normalized);
    }

    public function test_html_without_svg_is_returned_untouched(): void
    {
        $html = '<p>正文</p><p><img src="/cmf-media/a.png" data-cmf-media="3"></p>';

        $this->assertSame($html, (new SvgCardNormalizer)->normalize($html));
    }

    public function test_backs_up_only_safe_direct_opacity_animations_and_keeps_the_original(): void
    {
        $html = '<svg><g><animate attributeName="opacity" values="0;1" dur="2000ms" repeatCount="indefinite" style="box-sizing: border-box;"></animate>'
            .'<animate attributeName="opacity" values="1;0" dur="3s"></animate></g>'
            .'<g><animate attributeName="href" values="0;1" dur="2s"></animate></g>'
            .'<g><animate attributeName="opacity" values="0;2" dur="2s"></animate></g>'
            .'<g><rect><animate attributeName="opacity" values="0;1" dur="2s"></animate></rect></g>'
            .'<g><animate attributeName="opacity" values="0;1" dur="2s" onbegin="evil()"></animate></g></svg>';

        $normalized = (new SvgCardNormalizer)->normalize($html);
        $document = HtmlDocument::load($normalized);
        $groups = $document->getElementsByTagName('g');

        $this->assertSame('{"attributeName":"opacity","values":"0;1","dur":"2s","begin":"0s","repeatCount":"indefinite"}', $groups->item(0)->getAttribute('data-cmf-svg-opacity'));
        $this->assertSame(6, $document->getElementsByTagName('animate')->length);

        for ($index = 1; $index < $groups->length; $index++) {
            $this->assertSame('', $groups->item($index)->getAttribute('data-cmf-svg-opacity'));
        }

        $this->assertSame($normalized, (new SvgCardNormalizer)->normalize($normalized));
    }
}
