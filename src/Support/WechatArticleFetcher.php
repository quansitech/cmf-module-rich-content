<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use RuntimeException;

/**
 * 微信公众号文章抓取（仅 mp.weixin.qq.com / weixin.qq.com 单篇图文）。
 *
 * 安全约束：
 * - 主机白名单 + 仅 http(s) + 拒绝 userinfo / 非常规端口；
 * - 解析出的全部 IP 必须是公网地址，并用 CURLOPT_RESOLVE 固定解析结果（防 DNS rebinding）；
 * - 不自动跟随重定向，逐跳重新校验，最多 3 跳；
 * - 连接 / 总超时与响应体积上限，超限直接中断。
 *
 * 抓到的正文按「公众号 → 富文本」的口径处理：还原 data-src 懒加载图、剥脚本样式，
 * 再交给 HtmlSanitizer 白名单清洗、RemoteImageImporter 把图片落到 cmf-media。
 */
final class WechatArticleFetcher
{
    private const DEFAULT_ALLOWED_HOSTS = [
        'mp.weixin.qq.com',
        'weixin.qq.com',
    ];

    private const CONNECT_TIMEOUT = 5;

    private const REQUEST_TIMEOUT = 15;

    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param  (Closure(array{url: string, host: string, port: int, ips: list<string>}):
     *          array{status: int, location: string|null, body: string}|null)|null  $transport
     *                                                                                        测试用传输层
     */
    public function __construct(
        private readonly RemoteImageGuard $guard,
        private readonly HtmlSanitizer $sanitizer,
        private readonly RemoteImageImporter $importer,
        private readonly SvgCardNormalizer $svgCards,
        private readonly ?Closure $transport = null,
    ) {}

    /**
     * 抓取一篇文章并把正文图片本地化。
     *
     * @return ImportResult 正文 HTML（图片已指向 cmf-media）与本次新增的媒体 ID
     *
     * @throws InvalidArgumentException 链接不合法 / 不在白名单 / 指向内网
     * @throws RuntimeException 抓取失败或正文缺失
     */
    public function fetch(string $url, int|string|null $uploaderId = null): ImportResult
    {
        $target = $this->inspect($url);
        $html = $this->download($target);
        $body = $this->extractBody($html);

        if ($body === '') {
            throw new RuntimeException('没有抓到公众号正文，请确认链接是图文消息（mp.weixin.qq.com/s/…）且可公开访问');
        }

        $clean = $this->sanitizer->sanitize($this->svgCards->normalize($body));

        if (trim(strip_tags($clean)) === '' && ! str_contains($clean, '<img') && ! str_contains($clean, '<image')) {
            throw new RuntimeException('公众号正文是空的，可能是纯视频或纯图片消息，暂不支持');
        }

        /** @var array<string, mixed> $wechat */
        $wechat = (array) config('cmf-rich-content.wechat_article', []);

        // 单篇公众号图文常见 20~40 张图，抓取链路放宽预算；正文仍受 upload_rule 体积限制
        return $this->importer->importIntoHtml(
            $clean,
            $uploaderId,
            max(1, (int) ($wechat['max_images'] ?? 30)),
            max(5.0, (float) ($wechat['budget_seconds'] ?? 60)),
        );
    }

    /** 兼容原入口，正文 DOM 职责独立于网络编排。 */
    public function extractBody(string $html): string
    {
        return (new WechatArticleBody)->extract($html);
    }

    /**
     * @return array{url: string, host: string, port: int, ips: list<string>}
     */
    private function inspect(string $url): array
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('链接格式不正确');
        }

        if (! preg_match('~^https?://~i', $url)) {
            throw new InvalidArgumentException('只支持 http/https 链接');
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('链接格式不正确');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('链接格式不正确');
        }

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
        $host = trim($host, '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($host === '' || ! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('链接格式不正确');
        }

        if (! $this->hostAllowed($host)) {
            throw new InvalidArgumentException('只支持微信公众号文章链接（mp.weixin.qq.com）');
        }

        try {
            $target = $this->guard->inspect($url, [$host]);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('链接的域名解析不到公网地址', previous: $exception);
        }

        return $target;

    }

    private function hostAllowed(string $host): bool
    {
        $configured = config('cmf-rich-content.wechat_article.allowed_hosts', self::DEFAULT_ALLOWED_HOSTS);
        $hosts = is_array($configured) ? $configured : explode(',', (string) $configured);
        $hosts = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(rtrim(trim((string) $value), '.')),
            $hosts
        ))));

        $hosts = $hosts === [] ? self::DEFAULT_ALLOWED_HOSTS : $hosts;

        foreach ($hosts as $pattern) {
            if (fnmatch($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{url: string, host: string, port: int, ips: list<string>}  $target
     */
    private function download(array $target): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->transport instanceof Closure
                ? ($this->transport)($target)
                : $this->curlFetch($target);

            if ($response === null) {
                throw new RuntimeException('抓取失败：公众号没有响应，或响应体积超过上限');
            }

            $status = (int) ($response['status'] ?? 0);

            if ($status >= 300 && $status < 400 && is_string($response['location'] ?? null)) {
                $target = $this->inspect($this->absoluteUrl((string) $response['location'], $target['url']));

                continue;
            }

            if ($status >= 400 || $status === 0) {
                throw new RuntimeException('抓取失败：公众号返回 HTTP '.$status);
            }

            return (string) ($response['body'] ?? '');
        }

        throw new RuntimeException('抓取失败：重定向次数过多');
    }

    private function absoluteUrl(string $location, string $base): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri($location));
    }

    /**
     * @param  array{url: string, host: string, port: int, ips: list<string>}  $target
     * @return array{status: int, location: string|null, body: string}|null
     */
    private function curlFetch(array $target): ?array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('抓取失败：服务器缺少 curl 扩展');
        }

        $body = '';
        $tooLarge = false;
        $status = 0;
        $location = null;

        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $target['ips']
        );

        $options = [
            CURLOPT_URL => $target['url'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => (int) config('cmf-rich-content.wechat_article.timeout', self::REQUEST_TIMEOUT),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: zh-CN,zh;q=0.9'],
            CURLOPT_USERAGENT => (string) config(
                'cmf-rich-content.wechat_article.user_agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
            ),
            CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.implode(',', $addresses)],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$status, &$location): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $matches) === 1) {
                    $status = (int) $matches[1];
                } elseif (preg_match('~^Location:\s*(.+)$~i', trim($header), $matches) === 1) {
                    $location = trim($matches[1]);
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                $max = (int) config('cmf-rich-content.wechat_article.max_bytes', self::MAX_BYTES);

                if (strlen($chunk) > $max - strlen($body)) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        $handle = curl_init();

        if ($handle === false || ! curl_setopt_array($handle, $options)) {
            return null;
        }

        $result = curl_exec($handle);
        $error = curl_error($handle);

        // PHP 8.0+ 起句柄由 GC 回收，curl_close() 在 8.5 已废弃
        unset($handle);

        if ($tooLarge || $result === false || $error !== '') {
            return null;
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }
}
