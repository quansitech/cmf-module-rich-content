<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Quansitech\Cmf\RichContent\Support\TemporaryImageUpload;
use Quansitech\Cmf\RichContent\Tests\TestCase;
use RuntimeException;

final class TemporaryImageUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('http://rich-content.test/'));
        URL::forceRootUrl('http://rich-content.test');
        URL::forceScheme('http');
    }

    private function signed(string $filename, bool $expired = false): string
    {
        return URL::temporarySignedRoute('livewire.preview-file', $expired ? now()->subMinute() : now()->addMinutes(5), ['filename' => $filename], absolute: false);
    }

    public function test_accepts_valid_signed_relative_and_same_origin_absolute_previews(): void
    {
        $source = $this->signed('preview-image.png');
        $reader = new TemporaryImageUpload;
        $this->assertSame('preview-image.png', $reader->fileName($source));
        $this->assertSame('preview-image.png', $reader->fileName('http://rich-content.test'.$source));
    }

    public function test_accepts_real_livewire_encoded_original_filenames(): void
    {
        foreach (['a.png', '编辑器上传中文.png'] as $original) {
            $name = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(UploadedFile::fake()->create($original, 1, 'image/png'));
            $this->assertSame($name, (new TemporaryImageUpload)->fileName($this->signed($name)));
        }
    }

    #[DataProvider('invalidPreviews')]
    public function test_rejects_forged_expired_foreign_and_traversal_previews(string $kind): void
    {
        $source = $this->signed('preview-image.png');
        $source = match ($kind) {
            'forged' => preg_replace('/signature=[^&]+/', 'signature=forged', $source),
            'expired' => $this->signed('preview-image.png', true),
            'foreign' => 'https://evil.example'.$source,
            'traversal' => str_replace('preview-image.png', '%2e%2e%2fprivate.png', $source),
            'backslash' => str_replace('preview-image.png', 'a%5cprivate.png', $source),
        };
        $this->expectException(InvalidArgumentException::class);
        (new TemporaryImageUpload)->fileName($source);
    }

    public static function invalidPreviews(): array
    {
        return array_map(static fn (string $kind): array => [$kind], ['forged', 'expired', 'foreign', 'traversal', 'backslash']);
    }

    public function test_bare_temporary_paths_are_not_a_file_read_capability(): void
    {
        $this->assertNull((new TemporaryImageUpload)->fileName('livewire-tmp/private.png'));
    }

    public function test_copies_only_from_configured_temporary_directory_with_a_byte_limit(): void
    {
        Storage::fake('local');
        config(['livewire.temporary_file_upload.disk' => 'local', 'livewire.temporary_file_upload.directory' => 'configured-incoming']);
        Storage::disk('local')->put('configured-incoming/a.png', 'bytes');
        Storage::disk('local')->put('configured-incoming/a.png.json', '{"name":"中文.png"}');
        $path = tempnam(sys_get_temp_dir(), 'cmf-temp-test-');

        try {
            $this->assertSame('中文.png', (new TemporaryImageUpload)->copyTo('a.png', $path, 5));
            $this->assertSame('bytes', file_get_contents($path));
            $this->expectException(RuntimeException::class);
            (new TemporaryImageUpload)->copyTo('a.png', $path, 4);
        } finally {
            @unlink($path);
        }
    }
}
