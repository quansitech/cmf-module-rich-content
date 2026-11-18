<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\RichContent\Support\RemoteImageGuard;
use Quansitech\Cmf\RichContent\Support\RemoteImageImporter;
use Quansitech\Cmf\RichContent\Support\RichContentLocalizer;
use Quansitech\Cmf\RichContent\Tests\TestCase;

final class RemoteImageTransportTest extends TestCase
{
    private bool $canCreateMedia = true;

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Gate::before(fn (?Authenticatable $user, string $ability): ?bool => $ability === 'create' ? $this->canCreateMedia : null);
    }

    private function importer(array $ips = ['93.184.216.34']): RemoteImageImporter
    {
        $media = new class extends Media
        {
            public function url(int $ttl = 600): ?string
            {
                return '/cmf-media/test.png';
            }
        };
        $media->setRawAttributes(['id' => 7]);
        $uploader = $this->createStub(MediaUploader::class);
        $uploader->method('store')->willReturn($media);

        return new RemoteImageImporter(new RemoteImageGuard([], static fn (): array => $ips), $uploader);
    }

    public function test_localizer_without_media_permission_still_cleans_html_without_fetching_or_storing_images(): void
    {
        $this->canCreateMedia = false;
        Http::fake();
        $uploader = $this->createMock(MediaUploader::class);
        $uploader->expects($this->never())->method('store');
        $this->app->instance(RemoteImageImporter::class, new RemoteImageImporter(
            new RemoteImageGuard([], static function (): array {
                self::fail('Denied import must not resolve remote image addresses.');
            }),
            $uploader,
        ));
        $inline = 'data:image/png;base64,'.base64_encode(self::PNG);
        $result = $this->app->make(RichContentLocalizer::class)->prepareHtml(
            '<script>bad()</script><img src="https://cdn.example.com/a.png"><img src="'.$inline.'">',
            uploaderId: 42,
        );

        $this->assertStringNotContainsString('<script', $result->html);
        $this->assertStringContainsString('https://cdn.example.com/a.png', $result->html);
        $this->assertStringContainsString($inline, $result->html);
        $this->assertSame([], $result->imported);
        Http::assertNothingSent();
    }

    public function test_pins_each_hop_and_resolves_relative_redirects_without_proxy_or_auto_redirect(): void
    {
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = [$request->url(), $options];

            return count($seen) === 1
                ? Http::response('', 302, ['Location' => '../images/photo.png'])
                : Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
        });

        $result = $this->importer()->importIntoHtml('<img src="//cdn.example.com/posts/page.png">');

        $this->assertCount(1, $result->imported);
        $this->assertSame(['https://cdn.example.com/posts/page.png', 'https://cdn.example.com/images/photo.png'], array_column($seen, 0));
        foreach ($seen as [, $options]) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
            $this->assertSame(['cdn.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function test_rejects_redirect_to_private_address_before_sending_a_second_request(): void
    {
        Http::fake(static fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/private.png']));
        $html = '<img src="https://cdn.example.com/a.png">';
        $result = $this->importer()->importIntoHtml($html);
        $this->assertSame($html, $result->html);
        $this->assertCount(1, $result->failed);
        Http::assertSentCount(1);
    }

    public function test_total_budget_caps_request_timeout_and_zero_budget_never_sends(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertGreaterThan(0, $options['timeout']);
            $this->assertLessThanOrEqual(0.2, $options['timeout']);
            $this->assertLessThanOrEqual($options['timeout'], $options['connect_timeout']);

            return Http::response(self::PNG, 200);
        });
        $result = $this->importer()->importIntoHtml('<img src="https://cdn.example.com/a.png">', budgetSeconds: 0.2);
        $this->assertCount(1, $result->imported);
        $result = $this->importer()->importIntoHtml('<img src="https://cdn.example.com/a.png">', budgetSeconds: 0);
        $this->assertCount(1, $result->failed);
        Http::assertSentCount(1);
    }

    public function test_progress_and_final_file_size_both_enforce_maximum_bytes(): void
    {
        config(['cmf-rich-content.remote_images.max_bytes' => 16]);
        Http::fake(static fn () => Http::response(self::PNG, 200));
        $result = $this->importer()->importIntoHtml('<img src="https://cdn.example.com/a.png">');
        $this->assertSame([], $result->imported);
        $this->assertStringContainsString('体积上限', $result->failed[0]['reason']);

        Http::fake(static function ($request, array $options) {
            $options['progress'](0, 17);

            return Http::response(self::PNG, 200);
        });
        $result = $this->importer()->importIntoHtml('<img src="https://cdn.example.com/a.png">');
        $this->assertSame([], $result->imported);
        $this->assertStringContainsString('体积上限', $result->failed[0]['reason']);
    }
}
