<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

use Closure;
use InvalidArgumentException;

/**
 * URL 准入与 DNS 共用边界。调用方必须把 inspect() 返回的 IP 固定到本次连接，
 * 不能校验后再让 HTTP 客户端自行解析；每次重定向同样需要新建已校验目标。
 */
final class RemoteImageGuard
{
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain', '.home.arpa'];

    /**
     * @param  list<string>  $allowedHosts  空列表仅限制公网；非空按主机通配白名单
     * @param  (Closure(string): array<int, string>)|null  $resolver  测试注入的 DNS 解析器
     */
    public function __construct(
        private readonly array $allowedHosts = [],
        private readonly ?Closure $resolver = null,
    ) {}

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function assertAllowed(string $url): string
    {
        return $this->inspect($url)['url'];
    }

    /**
     * @param  list<string>|null  $allowedHosts  特定入口可覆盖主机白名单，公网限制不可覆盖
     * @return array{url: string, host: string, port: int, ips: list<string>}
     */
    public function inspect(string $url, ?array $allowedHosts = null): array
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('图片地址为空或包含非法字符');
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('只允许 http/https 图片');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('图片 URL 不允许携带用户信息');
        }

        $host = trim(strtolower(rtrim((string) ($parts['host'] ?? ''), '.')), '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('只允许 80/443 端口');
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // cURL 还接受整数/八进制/十六进制的 IPv4 简写；拒绝这类非标准主机，
        // 不让它绕过域名 CURLOPT_RESOLVE 而被底层直接当作地址连接。
        if ($host === '' || (! $literal && (preg_match('/^[a-z0-9.-]+$/D', $host) !== 1
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/iD', $host) === 1))) {
            throw new InvalidArgumentException('图片 URL 主机名不合法');
        }

        if ($host === 'localhost' || $host === 'metadata.google.internal') {
            throw new InvalidArgumentException('禁止访问本机/元数据地址');
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new InvalidArgumentException('禁止访问内网域名');
            }
        }

        $hosts = $allowedHosts ?? $this->allowedHosts;

        if ($hosts !== [] && ! array_filter($hosts, static fn (string $pattern): bool => fnmatch(strtolower(trim($pattern)), $host))) {
            throw new InvalidArgumentException('主机不在允许列表：'.$host);
        }

        $ips = $literal ? [$host] : $this->resolveHostIps($host);

        if ($ips === []) {
            throw new InvalidArgumentException('域名无法解析：'.$host);
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new InvalidArgumentException('域名解析到非公网地址：'.$host);
            }
        }

        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        $authority .= isset($parts['port']) ? ':'.$port : '';

        return [
            'url' => $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''),
            'host' => $host,
            'port' => $port,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    /** @return list<string> */
    public function resolveHostIps(string $host): array
    {
        if ($this->resolver instanceof Closure) {
            return array_values(array_unique(array_filter(array_map(
                static fn (mixed $ip): string => is_string($ip) ? trim($ip) : '',
                ($this->resolver)($host),
            ), static fn (string $ip): bool => $ip !== '')));
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
            $ip = (string) inet_ntop(substr($packed, 12));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // GLOBAL_RANGE 的实现不一定排除组播，服务端下载只允许单播地址。
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (ip2long($ip) & 0xF0000000) !== 0xE0000000;
        }

        return ! str_starts_with(strtolower($ip), 'ff');
    }
}
