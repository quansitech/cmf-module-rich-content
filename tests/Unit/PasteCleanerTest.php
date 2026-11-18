<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\RichContent\Support\PasteCleaner;

final class PasteCleanerTest extends TestCase
{
    public function test_preserves_empty_styled_decorations_and_their_parent_wrappers(): void
    {
        $html = '<section><div><span style="display: inline-block; width: 4px; height: 12px; background: #000"></span></div></section><p>正文</p>';

        $clean = (new PasteCleaner)->clean($html);

        $this->assertSame($html, $clean);
    }

    public function test_removes_unstyled_empty_blocks_but_keeps_line_breaks_and_text(): void
    {
        $html = '<section><div><span> </span></div></section><p><br></p><p>正文</p>';

        $clean = (new PasteCleaner)->clean($html);

        $this->assertSame('<p><br></p><p>正文</p>', $clean);
    }

    public function test_keeps_blank_paragraphs_typed_by_the_editor(): void
    {
        // 编辑器里连按回车会序列化成 <p>&nbsp;</p> / <p></p>，都是用户的空行意图（UXGH-106）
        $html = '<p>第一行</p><p>&nbsp;</p><p></p><p>第二行</p>';

        $clean = (new PasteCleaner)->clean($html);

        // 空段落原样保留（&nbsp; 会序列化成不换行空格字符）
        $this->assertSame(4, substr_count($clean, '<p>'));
        $this->assertStringContainsString('第一行', $clean);
        $this->assertStringContainsString('第二行', $clean);
    }

    public function test_keeps_paragraph_that_only_holds_an_uploaded_video(): void
    {
        $html = '<p><video src="/cmf-media/t4tstudio/video/1/a.mp4" controls="controls" width="640" height="360" data-cmf-media="1"></video></p>';

        $cleaned = (new PasteCleaner)->clean($html);

        $this->assertStringContainsString('<video', $cleaned);
        $this->assertStringContainsString('data-cmf-media="1"', $cleaned);
    }

    public function test_keeps_paragraph_that_only_holds_an_embedded_iframe(): void
    {
        $html = '<p>正文</p><p><iframe src="https://player.bilibili.com/player.html?aid=1" width="640" height="360"></iframe></p>';

        $clean = (new PasteCleaner)->clean($html);

        $this->assertStringContainsString('<iframe', $clean);
        $this->assertStringContainsString('player.bilibili.com', $clean);
    }
}
