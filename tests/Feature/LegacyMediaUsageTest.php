<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Feature;

use Composer\InstalledVersions;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Quansitech\Cmf\Media\Concerns\HasMedia;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\RichContent\Concerns\HasLocalizedRichContent;
use Quansitech\Cmf\RichContent\Support\RichContentLocalizer;
use Quansitech\Cmf\RichContent\Tests\TestCase;

final class LegacyMediaUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $root = InstalledVersions::getInstallPath('quansitech/cmf-module-media');
        (require $root.'/database/migrations/2025_01_01_000000_create_cmf_media_tables.php')->up();
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });
        config(['cmf-rich-content.remote_images.enabled' => false, 'cmf-media.auto_delete' => true]);
        Bus::fake([DeleteMediaJob::class]);
    }

    public function test_historical_usage_survives_domain_change_until_reference_is_removed(): void
    {
        $this->setDiskUrl('https://old.example.test/media');
        $media = $this->createMedia();
        $html = $this->image($media, (string) $media->url());
        $post = LegacyMediaPost::create(['title' => 'Before', 'content' => $html]);
        $this->assertSame(1, (int) $media->refresh()->ref_count);
        $usage = $post->mediaUsages()->where('field', 'content')->firstOrFail()->getKey();

        $this->setDiskUrl('https://new.example.test/media');
        $post->refresh()->update(['title' => 'After']);
        $this->assertSame($usage, $post->mediaUsages()->where('field', 'content')->firstOrFail()->getKey());
        $this->assertSame(1, (int) $media->refresh()->ref_count);
        $this->assertFalse($media->trashed());
        Bus::assertNotDispatched(DeleteMediaJob::class);

        // The same historical URL on another record does not acquire a usage.
        $other = LegacyMediaPost::create(['title' => 'Other', 'content' => $html]);
        $this->assertFalse($other->mediaUsages()->exists());

        $post->update(['content' => '<p>No image</p>']);
        $this->assertFalse($post->mediaUsages()->exists());
        $this->assertSame(0, (int) $media->refresh()->ref_count);
        $this->assertTrue($media->trashed());
        Bus::assertDispatched(DeleteMediaJob::class);
    }

    public function test_replacing_legacy_url_does_not_inherit_its_usage(): void
    {
        $this->setDiskUrl('https://cdn.example.test/media');
        $media = $this->createMedia();
        $post = LegacyMediaPost::create(['title' => 'Post', 'content' => $this->image($media, (string) $media->url())]);
        $this->assertTrue($post->mediaUsages()->exists());

        $post->update(['content' => $this->image($media, 'https://cdn.example.test/elsewhere/'.$media->path)]);
        $this->assertFalse($post->mediaUsages()->exists());
    }

    public function test_explicit_localization_matches_legacy_nodes_against_the_pre_save_body(): void
    {
        $this->setDiskUrl('https://old.example.test/media');
        $media = $this->createMedia();
        $html = $this->image($media, (string) $media->url());
        $post = LegacyMediaPost::create(['title' => 'Post', 'content' => $html]);
        $usage = $post->mediaUsages()->firstOrFail()->getKey();

        config(['cmf-rich-content.auto_localize' => false]);
        $this->setDiskUrl('https://new.example.test/media');
        $localizer = app(RichContentLocalizer::class);

        $post->content = $html.'<script>ignored</script>';
        $this->assertTrue($localizer->localize($post, 'content'));
        $this->assertSame($usage, $post->mediaUsages()->firstOrFail()->getKey());

        $post->content = $this->image($media, 'https://other.example.test/photo.png').'<script>ignored</script>';
        $this->assertTrue($localizer->localize($post, 'content'));
        $this->assertStringContainsString('https://other.example.test/photo.png', $post->getRawOriginal('content'));
        $this->assertFalse($post->mediaUsages()->exists());
    }

    public function test_unrelated_same_id_node_cannot_inherit_a_deleted_reference(): void
    {
        $this->setDiskUrl('https://old.example.test/media');
        $media = $this->createMedia();
        $valid = $this->image($media, (string) $media->url());
        $foreign = $this->image($media, 'https://old.example.test/elsewhere/'.$media->path);
        $post = LegacyMediaPost::create(['title' => 'Post', 'content' => $valid.$foreign]);
        $this->assertTrue($post->mediaUsages()->exists());

        $this->setDiskUrl('https://new.example.test/media');
        config(['cmf-rich-content.legacy_media_roots' => ['local' => ['https://old.example.test/media']]]);
        $post->update(['content' => $foreign]);
        $this->assertFalse($post->mediaUsages()->exists());
    }

    #[DataProvider('historicalRoots')]
    public function test_configured_old_root_preserves_the_real_node_when_a_mixed_set_changes(string $oldRoot): void
    {
        $this->setDiskUrl($oldRoot);
        $media = $this->createMedia();
        $valid = $this->image($media, (string) $media->url());
        $foreign = $this->image($media, 'https://other.example.test/photo.png');
        $post = LegacyMediaPost::create(['title' => 'Post', 'content' => $valid.$foreign]);
        $usage = $post->mediaUsages()->firstOrFail()->getKey();

        $this->setDiskUrl('https://new.example.test/media');
        config(['cmf-rich-content.legacy_media_roots' => ['local' => [$oldRoot]]]);
        $post->update(['content' => $valid]);
        $this->assertSame($usage, $post->mediaUsages()->firstOrFail()->getKey());
        $other = LegacyMediaPost::create(['title' => 'Other', 'content' => $valid]);
        $this->assertFalse($other->mediaUsages()->exists());
    }

    public static function historicalRoots(): array
    {
        return [
            'absolute old CDN' => ['https://old.example.test/media'],
            'root-relative local disk' => ['/cmf-media'],
        ];
    }

    private function setDiskUrl(string $url): void
    {
        config(['filesystems.disks.cmf-media-local' => [
            'driver' => 'local', 'root' => $this->app->storagePath('media'), 'url' => $url,
        ]]);
        Storage::forgetDisk('cmf-media-local');
    }

    private function createMedia(): Media
    {
        return Media::create([
            'disk' => 'local', 'path' => 'ab/photo.png', 'hash' => str_repeat('a', 32),
            'original_name' => 'photo.png', 'mime' => 'image/png', 'ext' => 'png', 'size' => 10,
        ]);
    }

    private function image(Media $media, string $url): string
    {
        return '<img data-id="'.$media->getKey().'" src="'.$url.'">';
    }
}

final class LegacyMediaPost extends Model implements HasRichContent
{
    use HasLocalizedRichContent;
    use HasMedia;

    protected $table = 'posts';

    protected $guarded = [];
}
