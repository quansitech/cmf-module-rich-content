<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;

/**
 * 读取 Livewire 预览图片的能力与预览端点一致：必须持有有效、未过期的相对签名。
 * 不再凭任意 URL 中的一段路径猜临时文件，避免越目录读取和公开化私有磁盘文件。
 */
final class TemporaryImageUpload
{
    public function fileName(string $source): ?string
    {
        $parts = parse_url($source);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('临时图片地址格式不正确');
        }

        $path = (string) ($parts['path'] ?? '');
        $preview = parse_url(URL::route('livewire.preview-file', ['filename' => '__cmf_filename__'], false), PHP_URL_PATH);
        $pattern = '~^'.str_replace('__cmf_filename__', '([^/]+)', preg_quote((string) $preview, '~')).'$~D';

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $name = rawurldecode($matches[1]);

        if (preg_match('/^[a-z0-9][a-z0-9._=+-]*$/iD', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException('临时图片文件名不合法');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $source) === 1) {
            throw new InvalidArgumentException('临时图片地址格式不正确');
        }

        // 相对签名本身不包含域名，所以绝对地址还必须与当前应用同源。
        if (isset($parts['host'])) {
            $current = parse_url(request()->getSchemeAndHttpHost());
            $port = static fn (array $url): int => (int) ($url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : 80));

            if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || strcasecmp((string) $parts['host'], (string) ($current['host'] ?? '')) !== 0
                || strtolower((string) $parts['scheme']) !== strtolower((string) ($current['scheme'] ?? ''))
                || $port($parts) !== $port($current)) {
                throw new InvalidArgumentException('临时图片必须使用应用同源的预览地址');
            }
        }

        if (! URL::hasValidRelativeSignature(Request::create($source))) {
            throw new InvalidArgumentException('临时图片签名无效或已过期，请重新上传图片');
        }

        return $name;
    }

    public function copyTo(string $name, string $path, int $maxBytes): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._=+-]*$/iD', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException('临时图片文件名不合法');
        }

        $disk = Storage::disk(config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));
        $directory = trim((string) (config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp'), '/');
        $key = $directory.'/'.$name;

        if (! $disk->exists($key)) {
            throw new RuntimeException('临时上传文件不存在或已过期，请重新上传图片');
        }

        if ((int) $disk->size($key) > $maxBytes) {
            throw new RuntimeException('图片超过体积上限');
        }

        $source = $disk->readStream($key);
        $target = fopen($path, 'wb');

        try {
            if (! is_resource($source) || ! is_resource($target)) {
                throw new RuntimeException('无法读取临时上传文件');
            }

            // 即使底层 size 元信息不准确，也最多复制 maxBytes + 1 后判定超限。
            $copied = stream_copy_to_stream($source, $target, $maxBytes + 1);

            if ($copied === false || $copied <= 0 || $copied > $maxBytes) {
                throw new RuntimeException('临时图片为空或超过体积上限');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $this->originalName($disk, $key);
    }

    private function originalName(Filesystem $disk, string $key): ?string
    {
        if (! $disk->exists($key.'.json')) {
            return null;
        }

        $metadata = json_decode((string) $disk->get($key.'.json'), true);
        $name = is_array($metadata) ? ($metadata['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? basename($name) : null;
    }
}
