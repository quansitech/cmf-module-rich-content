<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\RichContent\Support\ImportResult;
use Quansitech\Cmf\RichContent\Support\MediaReferences;

final class MediaReferencesTest extends TestCase
{
    #[DataProvider('invalidIds')]
    public function test_rejects_anything_other_than_a_complete_positive_integer(mixed $value): void
    {
        $this->assertNull(MediaReferences::id($value));
    }

    public static function invalidIds(): array
    {
        return array_map(static fn (mixed $value): array => [$value], [
            '', '0', '-1', '+1', '01', '7uuid', '1.5', '1e2', '999999999999999999999999999',
            'd1ddb3fb-d1d8-43ed-8023-a66a8fb5cbe1', null, false, true, 1.5, [],
        ]);
    }

    public function test_uses_image_and_video_nodes_and_deduplicates_existing_and_new_references(): void
    {
        $html = '<p data-cmf-media="90">text data-cmf-media="91"</p><img data-cmf-media="7" src="/old.png">'
            .'<img data-cmf-media="7"><img data-cmf-media="8"><img data-cmf-media="7uuid">'
            .'<video data-cmf-media="9" src="/video.mp4" controls></video>';

        $this->assertSame([7, 8, 9], MediaReferences::ids($html));
        $this->assertSame([7, 8, 9], (new ImportResult($html))->mediaIds());
        $this->assertSame(7, MediaReferences::id('7'));
        $this->assertSame(7, MediaReferences::id(7));
    }

    public function test_uploaded_video_reference_is_synchronised(): void
    {
        $html = '<p>看视频</p><video data-cmf-media="9" src="/video.mp4" controls></video>';

        $this->assertSame([9], MediaReferences::ids($html));
        $this->assertSame([9], MediaReferences::allIds($html));
    }

    public function test_counts_svg_card_images_as_media_references(): void
    {
        // 公众号 SVG 卡片的图片是 <image href>，不登记引用就会被 prune-orphans 当孤儿删掉
        $html = '<svg viewBox="0 0 10 10"><g><image href="/cmf-media/a.png" data-cmf-media="11"></image></g></svg>';

        $this->assertSame([11], MediaReferences::ids($html));
        $this->assertSame([11], MediaReferences::allIds($html));
    }

    public function test_ignores_third_party_data_id_attributes(): void
    {
        // 公众号/第三方编辑器自带的数字 data-id 不能当成媒体引用（否则漏下载或串图）。
        $html = '<img src="https://mmbiz.qpic.cn/a.png" data-id="123">'
            .'<img src="https://mmbiz.qpic.cn/b.png" data-id="d1ddb3fb-d1d8-43ed-8023-a66a8fb5cbe1">';

        $this->assertSame([], MediaReferences::ids($html));
        $this->assertSame([], MediaReferences::allIds($html, [123 => $this->legacyMedia(123)]));
        $this->assertSame(123, MediaReferences::legacyId('123'));
        $this->assertNull(MediaReferences::legacyId('12x'));
    }

    public function test_keeps_legacy_reference_even_when_the_new_attribute_is_invalid(): void
    {
        // 新属性指向已物删的 id、旧属性仍指向在用媒体时，同步口径要两个都留
        // （不存在的那个交给 sync() 的 whereIn 过滤），否则旧引用会被静默丢掉。
        $html = '<img src="https://storage.example.com/cmf-media/82/abc.png?signature=old" data-cmf-media="999999" data-id="82">';

        $this->assertSame([999999, 82], MediaReferences::allIds($html, [82 => $this->legacyMedia(82)]));
        $this->assertSame([999999], MediaReferences::ids($html));
    }

    public function test_legacy_cloud_reference_requires_the_full_origin_and_path(): void
    {
        $media = $this->legacyMedia(82);

        $this->assertTrue(MediaReferences::pointsAtMedia('https://storage.example.com:443/cmf-media/82/abc.png?signature=old', $media));
        $this->assertFalse(MediaReferences::pointsAtMedia('https://storage.example.com/elsewhere/82/abc.png', $media));
        $this->assertFalse(MediaReferences::pointsAtMedia('/cmf-media/82/abc.png', $media));
        $this->assertFalse(MediaReferences::pointsAtMedia('https://storage.example.com:444/cmf-media/82/abc.png', $media));
    }

    private function legacyMedia(int $id): Media
    {
        $media = new class extends Media
        {
            public function url(int $ttl = 600): ?string
            {
                return 'https://storage.example.com/cmf-media/'.$this->path.'?signature=new';
            }
        };
        $media->setRawAttributes(['id' => $id, 'path' => $id.'/abc.png']);

        return $media;
    }
}
