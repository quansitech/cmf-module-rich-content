<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\RichContent\Support\RemoteImageGuard;

/**
 * 远程图片准入（SSRF 防线）矩阵：
 *
 * - 协议/端口/用户信息/本机与元数据主机名一律拒绝；
 * - 域名解析结果必须是公网地址（含 CGNAT 100.64/10），测试注入 resolver 不依赖真实 DNS；
 * - 白名单为空表示只限制公网，非空时按 fnmatch 通配。
 */
class RemoteImageGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            '回环 IP' => ['http://127.0.0.1/a.png'],
            'localhost 主机名' => ['https://localhost/a.png'],
            '内网域名后缀' => ['https://intranet.local/a.png'],
            '非 http 协议' => ['ftp://cdn.example.com/a.png'],
            '非 80/443 端口' => ['https://cdn.example.com:8443/a.png'],
            '携带用户信息' => ['https://user:secret@cdn.example.com/a.png'],
            '元数据服务' => ['http://metadata.google.internal/computeMetadata/v1/'],
            '空地址' => ['   '],
            'IPv4 multicast' => ['http://224.0.0.1/a.png'],
            'IPv6 multicast' => ['http://[ff02::1]/a.png'],
            'hex mapped loopback' => ['http://[::ffff:7f00:1]/a.png'],
            'expanded mapped loopback' => ['http://[0:0:0:0:0:ffff:7f00:1]/a.png'],
            'integer IPv4' => ['http://2130706433/a.png'],
            'short IPv4' => ['http://127.1/a.png'],
            'hex IPv4' => ['http://0x7f000001/a.png'],
            'backslash host confusion' => ['http://public.example\\@127.0.0.1/a.png'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_rejects_unsafe_urls(string $url): void
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => ['93.184.216.34']);

        $this->assertFalse($guard->isAllowed($url));
        $this->expectException(InvalidArgumentException::class);
        $guard->assertAllowed($url);
    }

    public function test_allows_public_host_resolving_to_public_ip(): void
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => ['93.184.216.34']);

        $this->assertTrue($guard->isAllowed('https://cdn.example.com/a.png'));
        $this->assertSame('https://cdn.example.com/a.png', $guard->assertAllowed('https://cdn.example.com/a.png'));
    }

    public function test_protocol_relative_url_is_upgraded_to_https(): void
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => ['93.184.216.34']);

        $this->assertSame('https://cdn.example.com/a.png', $guard->assertAllowed('//cdn.example.com/a.png'));
    }

    public function test_rejects_public_hostname_resolving_to_private_ip(): void
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => ['10.0.0.8']);

        $this->assertFalse($guard->isAllowed('https://rebind.example.com/a.png'));
    }

    public function test_rejects_direct_private_and_cgnat_ips(): void
    {
        $guard = new RemoteImageGuard;

        $this->assertFalse($guard->isAllowed('http://192.168.1.10/a.png'));
        $this->assertFalse($guard->isAllowed('http://100.64.0.10/a.png'));
        $this->assertTrue($guard->isAllowed('http://93.184.216.34/a.png'));
    }

    public function test_whitelist_supports_wildcards(): void
    {
        $guard = new RemoteImageGuard(['*.example.com'], static fn (string $host): array => ['93.184.216.34']);

        $this->assertTrue($guard->isAllowed('https://img.example.com/a.png'));
        $this->assertFalse($guard->isAllowed('https://evil.test/a.png'));
    }

    public function test_unresolvable_host_is_rejected(): void
    {
        $guard = new RemoteImageGuard([], static fn (string $host): array => []);

        $this->assertFalse($guard->isAllowed('https://nowhere.example.com/a.png'));
    }

    public function test_ipv6_literal_is_checked_without_url_brackets(): void
    {
        $guard = new RemoteImageGuard;

        // parse_url 会把 [::1] 连方括号一起返回，必须先剥掉再按 IP 判断
        $this->assertFalse($guard->isAllowed('http://[::1]/a.png'));
        $this->assertFalse($guard->isAllowed('http://[fe80::1]/a.png'));
        $this->assertTrue($guard->isAllowed('http://[240e:e1:a800:121::26]/a.png'));
    }

    public function test_inspect_returns_the_same_approved_dns_snapshot_for_transport(): void
    {
        $calls = 0;
        $guard = new RemoteImageGuard([], static function () use (&$calls): array {
            $calls++;

            return ['93.184.216.34', '93.184.216.34'];
        });
        $this->assertSame([
            'url' => 'https://cdn.example.com/a.png', 'host' => 'cdn.example.com', 'port' => 443, 'ips' => ['93.184.216.34'],
        ], $guard->inspect('//CDN.EXAMPLE.COM/a.png'));
        $this->assertSame(1, $calls);
        $this->assertFalse((new RemoteImageGuard([], static fn (): array => ['93.184.216.34', '10.0.0.1']))->isAllowed('https://cdn.example.com/a.png'));
    }
}
