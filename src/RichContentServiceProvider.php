<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Quansitech\Cmf\RichContent\Console\InstallRichContent;
use Quansitech\Cmf\RichContent\Support\HtmlSanitizer;
use Quansitech\Cmf\RichContent\Support\MediaUrlResolver;
use Quansitech\Cmf\RichContent\Support\PasteCleaner;
use Quansitech\Cmf\RichContent\Support\RemoteImageGuard;
use Quansitech\Cmf\RichContent\Support\RemoteImageImporter;
use Quansitech\Cmf\RichContent\Support\RichContentLocalizer;
use Quansitech\Cmf\RichContent\Support\WechatArticleFetcher;

/**
 * 富文本扩展包的服务注册：合并配置、注册无状态服务、挂一条后台抓取路由。
 *
 * 包本身不改动任何模型；宿主项目按需 `use HasLocalizedRichContent` 即可获得
 * 「保存时远程图落地」。路由只有登录用户可用，用于把公众号文章抓进编辑器。
 */
final class RichContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cmf-rich-content.php', 'cmf-rich-content');

        $this->app->singleton(RemoteImageGuard::class, static function (): RemoteImageGuard {
            /** @var array<int, mixed> $hosts */
            $hosts = (array) config('cmf-rich-content.remote_images.allowed_hosts', []);

            return new RemoteImageGuard(array_values(array_filter($hosts, 'is_string')));
        });

        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(PasteCleaner::class);
        $this->app->singleton(RemoteImageImporter::class);
        $this->app->singleton(RichContentLocalizer::class);
        $this->app->singleton(MediaUrlResolver::class);
        $this->app->singleton(WechatArticleFetcher::class);
    }

    public function boot(): void
    {
        $this->registerDefaultUploadRules();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cmf-rich-content');

        // 抓取会消耗外网连接和存储额度，按用户独立限流，不能只依赖登录与域名白名单。
        RateLimiter::for('cmf-rich-content-wechat-article', static fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('cmf-rich-content.wechat_article.requests_per_minute', 6)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // 视频上传与地址解析共用同一份额度：都是登录用户手动触发的低频操作。
        RateLimiter::for('cmf-rich-content-video', static fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('cmf-rich-content.video.requests_per_minute', 10)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // 图片兜底端点：只在直传被 CORS 拦时才会命中，额度给得比视频宽一些。
        RateLimiter::for('cmf-rich-content-image', static fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('cmf-rich-content.image.requests_per_minute', 20)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([InstallRichContent::class]);

        $this->publishes([
            __DIR__.'/../config/cmf-rich-content.php' => config_path('cmf-rich-content.php'),
        ], 'cmf-rich-content-config');
    }

    /** Default rules narrow the host's MIME list; never expand it or overwrite a named rule. */
    private function registerDefaultUploadRules(): void
    {
        $allowed = (array) config('cmf-media.allowed_mimes', []);
        foreach (['cmf-rich-image' => ['image', 10], 'cmf-rich-video' => ['video', 500]] as $name => [$type, $limit]) {
            $key = 'cmf-media.rules.'.$name;
            if (config()->has($key)) {
                continue;
            }

            $mimes = array_values(array_filter($allowed, static fn (mixed $mime): bool => is_string($mime) && (str_starts_with($mime, $type.'/') || ($type === 'video' && $mime === 'application/mp4'))));

            config([$key => ['mimes' => $mimes, 'max_size' => $limit * 1024 * 1024, 'disposition' => 'inline']]);
        }
    }
}
