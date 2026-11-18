<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use DOMElement;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;
use RuntimeException;
use Throwable;

/**
 * 把富文本 HTML 里的远程图片下载到 cmf-media。
 *
 * 处理顺序：遍历 <img> → 取 data-src/data-original/src → SSRF 校验 → 下载到
 * 临时文件 → finfo 探测 MIME → 交给 MediaUploader（内容哈希去重、落盘、
 * 建档）→ 回写 src 为媒体访问地址并补 data-cmf-media。单张失败只记日志，不阻断
 * 保存；超过单次张数或总时长预算的图片保持原样。
 */
final class RemoteImageImporter
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
        'image/tiff' => 'tiff',
    ];

    public function __construct(
        private readonly RemoteImageGuard $guard,
        private readonly MediaUploader $uploader,
    ) {}

    public function importIntoHtml(
        string $html,
        int|string|null $uploaderId = null,
        ?int $maxImages = null,
        ?float $budgetSeconds = null,
    ): ImportResult {
        /** @var array<string, mixed> $config */
        $config = (array) config('cmf-rich-content.remote_images', []);

        // SVG 卡片图（公众号动画）以 <image href> 形式出现，和 <img> 走同一套下载与本地化
        if (($config['enabled'] ?? true) !== true || preg_match('/<img\b|<image\b/i', $html) !== 1) {
            return new ImportResult($html);
        }

        $document = HtmlDocument::load($html);

        /** @var list<DOMElement> $images */
        $images = [];

        foreach (['img', 'image'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $image) {
                if ($image instanceof DOMElement) {
                    $images[] = $image;
                }
            }
        }

        /** @var list<array{source: string, media_id: int, url: string}> $imported */
        $imported = [];

        /** @var list<array{source: string, reason: string}> $failed */
        $failed = [];

        $canCreateMedia = null;
        $attempted = 0;
        $adopted = 0;
        $maxImages = max(0, $maxImages ?? (int) ($config['max_images_per_save'] ?? 10));
        $deadline = microtime(true) + max(0.0, $budgetSeconds ?? (float) ($config['total_budget_seconds'] ?? 20));

        foreach ($images as $image) {
            // 已带 data-cmf-media 的图片是媒体库附件，字节早在本地，无需再下载。
            // 编辑器直传图片在保存时该属性还不存在（预览地址也没有新属性），必须继续转存。
            if (MediaReferences::id($image->getAttribute(MediaReferences::ATTRIBUTE)) !== null) {
                continue;
            }

            // 升级自愈：旧版正文只有 data-id。只有当 src 确实指向本系统媒体地址时
            // 才就地换属性（不重复下载）；第三方内容里的数字 data-id 不会被当成媒体。
            if ($this->adoptLegacyReference($image)) {
                $adopted++;

                continue;
            }

            $source = $this->resolveSource($image);

            if ($source === null) {
                continue;
            }

            $canCreateMedia ??= Gate::allows('create', config('cmf-media.model', Media::class));
            if (! $canCreateMedia) {
                continue;
            }

            if ($attempted >= $maxImages || microtime(true) > $deadline) {
                $failed[] = ['source' => $source, 'reason' => '超出本次处理预算'];

                continue;
            }

            $attempted++;

            try {
                $media = $this->importOne($source, $uploaderId, $config, $deadline);
                $url = $media->url();

                if ($url === null || $url === '') {
                    throw new RuntimeException('无法生成媒体访问地址');
                }

                $image->setAttribute(MediaReferences::ATTRIBUTE, (string) $media->id);

                if (strtolower($image->tagName) === 'image') {
                    // SVG <image> 用 href 指向资源，xlink:href 兼容旧浏览器
                    $image->setAttribute('href', $url);
                    $image->setAttribute('xlink:href', $url);

                    foreach (['data-id', 'data-src', 'data-original'] as $attribute) {
                        $image->removeAttribute($attribute);
                    }
                } else {
                    $image->setAttribute('src', $url);

                    // 落地成功后清掉第三方/旧版的 data-id，避免与媒体 id 混淆。
                    foreach (['data-id', 'data-src', 'data-original', 'srcset'] as $attribute) {
                        $image->removeAttribute($attribute);
                    }
                }

                $imported[] = ['source' => $source, 'media_id' => (int) $media->id, 'url' => $url];
            } catch (Throwable $exception) {
                $failed[] = ['source' => $source, 'reason' => $exception->getMessage()];

                Log::warning('cmf-rich-content.remote-image.failed', [
                    'source' => Str::limit($source, 200),
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        // 只有旧标记自愈、没有新下载时也必须回写，否则新属性改动会丢在原 HTML 里。
        if ($imported === [] && $adopted === 0) {
            return new ImportResult($html, [], $failed);
        }

        return new ImportResult(HtmlDocument::bodyHtml($document), $imported, $failed);
    }

    private function resolveSource(DOMElement $image): ?string
    {
        $attributes = strtolower($image->tagName) === 'image'
            ? ['href', 'xlink:href', 'data-src']
            : ['data-src', 'data-original', 'src'];

        foreach ($attributes as $attribute) {
            $value = trim($image->getAttribute($attribute));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    /**
     * 把旧版正文里的 data-id 就地升级为 data-cmf-media。
     *
     * 只有「该 id 确实对应媒体记录」且「src 指向本系统媒体地址」两个条件同时成立才认，
     * 因此第三方内容自带的数字 data-id 不会被误当成媒体引用（不会漏下载、不会串图）。
     */
    private function adoptLegacyReference(DOMElement $image): bool
    {
        $legacyId = MediaReferences::legacyId($image->getAttribute('data-id'));

        if ($legacyId === null) {
            return false;
        }

        // 只认「src 确实是这张媒体」的旧引用，第三方正文里同号的 data-id 不会被采纳
        $media = MediaReferences::legacyMedia($legacyId, $image->getAttribute('src'));

        if (! $media instanceof Media) {
            return false;
        }

        $image->setAttribute(MediaReferences::ATTRIBUTE, (string) $media->id);
        $image->removeAttribute('data-id');

        // 旧正文可能存的是过期签名地址：升级时换成当前地址，避免读到旧图
        try {
            $url = (string) $media->url();
        } catch (Throwable) {
            $url = '';
        }

        if ($url !== '') {
            $image->setAttribute('src', $url);
        }

        return true;
    }

    private function importOne(string $source, int|string|null $uploaderId, array $config, float $deadline): Media
    {
        [$path, $name] = $this->fetch($source, $config, $deadline);

        try {
            $rule = UploadRule::resolve((string) ($config['upload_rule'] ?? 'editor'));

            return $this->uploader->store(
                new UploadedFile($path, $name, null, null, true),
                uploaderId: $uploaderId,
                rule: $rule,
            );
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string} 临时文件路径与建议文件名
     */
    private function fetch(string $source, array $config, float $deadline): array
    {
        $maxBytes = max(1, (int) ($config['max_bytes'] ?? 5 * 1024 * 1024));
        $path = tempnam(sys_get_temp_dir(), 'cmf-rc-');

        if ($path === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        $preferredName = null;

        try {
            $temporary = new TemporaryImageUpload;
            $temporaryName = $temporary->fileName($source);

            if ($temporaryName !== null) {
                // 编辑器直接上传的图片：Livewire 先落到 livewire-tmp，保存时还在预览地址上，
                // 此时没有必要（也下载不到）走 HTTP，直接从临时盘搬到媒体库。
                $preferredName = $temporary->copyTo($temporaryName, $path, $maxBytes);
            } elseif (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $source) === 1) {
                $this->writeInlineImage($path, $source, $maxBytes);
            } else {
                $this->fetchRemote($path, $source, $config, $maxBytes, $deadline);
            }

            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';

            // 不信任响应头、也不信任 data: 的自述类型，一律以文件内容为准
            if (! str_starts_with($detected, 'image/') || $detected === 'image/svg+xml') {
                throw new RuntimeException('不是可用的图片内容：'.($detected !== '' ? $detected : 'unknown'));
            }

            $size = (int) @filesize($path);

            if ($size <= 0) {
                throw new RuntimeException('图片内容为空');
            }

            if ($size > $maxBytes) {
                throw new RuntimeException('图片超过体积上限');
            }

            return [$path, $preferredName ?? $this->fileName($source, $detected)];
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }

    private function writeInlineImage(string $path, string $source, int $maxBytes): void
    {
        $payload = (string) preg_replace('/\s+/', '', (string) substr($source, (int) strpos($source, ',') + 1));

        if ((int) (strlen($payload) * 0.75) > $maxBytes) {
            throw new RuntimeException('图片超过体积上限');
        }

        $binary = base64_decode($payload, true);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('内联图片 base64 解码失败');
        }

        if (file_put_contents($path, $binary) === false) {
            throw new RuntimeException('内联图片写入临时文件失败');
        }
    }

    /** @param array<string, mixed> $config */
    private function fetchRemote(string $path, string $url, array $config, int $maxBytes, float $deadline): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('远程图片下载需要 cURL，不能回落到未固定 DNS 的传输层');
        }

        $maxRedirects = max(0, (int) ($config['max_redirects'] ?? 3));

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $target = $this->guard->inspect($url);
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new RuntimeException('超出本次处理预算');
            }

            $timeout = min(max(0.001, (float) ($config['timeout'] ?? 5)), $remaining);
            $addresses = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $target['ips']);
            // 每跳使用新的请求和校验结果；禁用系统代理，防止代理代为解析而绕过 pin。
            $response = Http::withHeaders([
                'User-Agent' => (string) ($config['user_agent'] ?? 'Mozilla/5.0 (compatible; cmf-rich-content/1.0)'),
                'Accept' => 'image/*,*/*;q=0.8',
            ])
                ->setHandler(new CurlHandler)
                ->connectTimeout(min(max(0.001, (float) ($config['connect_timeout'] ?? 3)), $timeout))
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'curl' => [
                        CURLOPT_PROXY => '',
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.implode(',', $addresses)],
                    ],
                    'progress' => static function (int $total, int $downloaded) use ($maxBytes, $deadline): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('图片超过体积上限');
                        }
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('超出本次处理预算');
                        }
                    },
                ])
                ->sink($path)
                ->get($target['url']);

            if ($response->redirect() && $response->header('Location') !== '') {
                if ($hop >= $maxRedirects) {
                    throw new RuntimeException('下载失败：重定向次数过多');
                }

                $url = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->header('Location')));

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('下载失败：HTTP '.$response->status());
            }

            if ((int) @filesize($path) > $maxBytes) {
                throw new RuntimeException('图片超过体积上限');
            }

            return;
        }
    }

    private function fileName(string $source, string $mime): string
    {
        $base = basename((string) parse_url($source, PHP_URL_PATH));
        $base = (string) preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $base);
        $base = trim($base, '-.');

        if ($base === '') {
            $base = 'remote-'.substr(md5($source), 0, 8);
        }

        $extension = self::MIME_EXTENSIONS[$mime] ?? '';

        if ($extension !== '' && ! str_ends_with(strtolower($base), '.'.$extension)) {
            $base = (string) preg_replace('/\.[a-z0-9]{1,10}$/i', '', $base).'.'.$extension;
        }

        return mb_substr($base, 0, 120);
    }
}
