<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Providers;

use Composer\InstalledVersions;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

/** Reuse TinyEditor discovery and supply this module's defaults and local assets. */
final class TinyMceServiceProvider extends ServiceProvider
{
    public const ASSET_TAG = 'cmf-rich-content-assets';

    private const VENDOR_ASSETS = 'amidesfahani/filament-tinyeditor';

    private const PACKAGE_ASSETS = 'quansitech/cmf-module-rich-content';

    /**
     * 内联 SVG（公众号动画卡片）在 TinyMCE 里的 schema 放行清单。
     *
     * TinyMCE 默认 schema 不认识 svg，setContent 时会把整棵子树丢掉；这里按元素逐个列出属性，
     * 与 HtmlSanitizer::SVG_ELEMENTS 保持同一口径（服务端仍会再清洗一次）。
     * 注意 valid_children 只能写 '+body[svg]'：再加 '+svg[g]' / '+g[image]' 会让 schema 解析卡死。
     */
    private const SVG_VALID_ELEMENTS = 'svg[viewbox|xmlns|width|height|x|y|preserveaspectratio|style|fill|stroke|opacity|version],'
        .'g[transform|style|opacity|fill|stroke|display|visibility],'
        .'defs[style],'
        .'path[d|fill|stroke|stroke-width|stroke-linecap|stroke-linejoin|fill-rule|clip-rule|opacity|style|transform],'
        .'rect[x|y|width|height|rx|ry|fill|stroke|stroke-width|opacity|style|transform],'
        .'circle[cx|cy|r|fill|stroke|stroke-width|opacity|style],'
        .'ellipse[cx|cy|rx|ry|fill|stroke|stroke-width|opacity|style],'
        .'line[x1|y1|x2|y2|stroke|stroke-width|opacity|style],'
        .'polyline[points|fill|stroke|stroke-width|opacity|style],'
        .'polygon[points|fill|stroke|stroke-width|opacity|style],'
        .'text[x|y|dx|dy|font-size|font-family|font-weight|fill|text-anchor|opacity|style|transform],'
        .'tspan[x|y|dx|dy|font-size|fill|opacity|style],'
        .'image[href|xlink:href|x|y|width|height|preserveaspectratio|style|data-cmf-media],'
        .'animate[attributename|values|from|to|by|dur|begin|end|repeatcount|fill|keytimes|keysplines|calcmode|restart|type|additive|accumulate|style],'
        .'animatetransform[attributename|type|values|from|to|by|dur|begin|end|repeatcount|fill|keytimes|keysplines|calcmode|restart|additive|accumulate|style],'
        .'animatemotion[path|values|from|to|by|dur|begin|end|repeatcount|fill|keytimes|keysplines|calcmode|restart|style],'
        .'set[attributename|to|begin|end|dur|fill|repeatcount|style],'
        .'lineargradient[id|gradientunits|x1|y1|x2|y2|style],'
        .'radialgradient[id|gradientunits|cx|cy|r|fx|fy|style],'
        .'stop[offset|stop-color|stop-opacity|style],'
        .'clippath[id|clippathunits|style],'
        .'mask[id|maskunits|style],'
        .'title,desc';

    public function register(): void
    {
        if (! $this->app->configurationIsCached()) {
            // The upstream provider may already have merged its defaults. Only an
            // actual host config file should override this module's baseline.
            $hostPath = $this->app->configPath('filament-tinyeditor.php');
            $host = is_file($hostPath) ? (array) require $hostPath : [];

            config(['filament-tinyeditor' => array_replace_recursive(
                require __DIR__.'/../../config/filament-tinyeditor.php',
                $host,
            )]);
        }

        // 编辑器侧的自动沙箱排除清单与服务端 iframe 白名单共用一份配置：
        // 否则媒体对话框插入的播放器会被 TinyMCE 加上缺 allow-scripts 的 sandbox，控制台报错且无法播放。
        $embedHosts = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('cmf-rich-content.embeds.allowed_hosts', []),
        )));

        // 注意不要给 image_class_list 兜底空数组：image 插件的 Class 下拉框按该选项渲染，
        // 传 [] 会得到一个 0 选项的 listbox，Alloy 校验时报
        // "Could not find valid *required* value for \"classes\""，图片对话框点保存直接失败。
        // 上游 TinyEditor::getImageClassList() 默认是 false，TinyMCE 只会在控制台 warn 一句
        // "Invalid value passed for the image_class_list option" 并忽略该选项，功能不受影响；
        // 需要图片样式类时在 profile 的 custom_configs 里给一个非空列表覆盖即可。
        foreach (array_keys((array) config('filament-tinyeditor.profiles', [])) as $profile) {
            $custom = (array) config("filament-tinyeditor.profiles.{$profile}.custom_configs", []);

            if ($embedHosts !== []) {
                $custom['sandbox_iframes_exclusions'] = $embedHosts;
            }

            // 公众号 SVG 动画卡片：默认 schema 会把 <svg> 整棵丢掉，需显式放行
            $children = trim((string) ($custom['valid_children'] ?? ''), ',');
            $elements = trim((string) ($custom['extended_valid_elements'] ?? ''), ',');
            if (! in_array('+body[svg]', explode(',', $children), true)) {
                $custom['valid_children'] = $children === '' ? '+body[svg]' : $children.',+body[svg]';
            }
            if (! str_contains($elements, self::SVG_VALID_ELEMENTS)) {
                $custom['extended_valid_elements'] = $elements === '' ? self::SVG_VALID_ELEMENTS : $elements.','.self::SVG_VALID_ELEMENTS;
            }

            config(["filament-tinyeditor.profiles.{$profile}.custom_configs" => $custom]);
        }
    }

    /**
     * 把 cmf-media 的浏览器直传端点交给编辑器 JS（check 秒传 / sign 签凭证 / callback 建档）。
     *
     * 图片与视频共用同一套协议：云存储下由浏览器直接 PUT 到桶里，不经过 PHP，因此不受
     * upload_max_filesize 限制；路径取自路由名而非硬编码（cmf-media.route_prefix 可配）。
     */
    private function injectMediaUploadEndpoints(): void
    {
        $prefix = trim((string) config('cmf-media.route_prefix', 'cmf-media'), '/');

        // 一律给同源相对路径：编辑器页可能挂在别的域名/IP 上，绝对地址会让 CSRF 与 Worker 跨源。
        $path = static fn (string $name): string => (string) parse_url(
            app('router')->has('cmf-media.'.$name) ? route('cmf-media.'.$name) : url($prefix.'/'.$name),
            PHP_URL_PATH,
        );
        $assetPath = static fn (string $file): string => (string) parse_url(asset($file), PHP_URL_PATH);

        // 中转兜底是本包自己的路由，不能套 cmf-media 的前缀。
        $fallback = app('router')->has('cmf-rich-content.video')
            ? (string) parse_url(route('cmf-rich-content.video'), PHP_URL_PATH)
            : '/cmf-rich-content/video';
        $imageFallback = app('router')->has('cmf-rich-content.image')
            ? (string) parse_url(route('cmf-rich-content.image'), PHP_URL_PATH)
            : '/cmf-rich-content/image';

        $endpoints = [
            'check' => $path('check'),
            'sign' => $path('sign'),
            'callback' => $path('callback'),
            'fallback' => $fallback,
            'image_fallback' => $imageFallback,
            'hash_worker' => $assetPath('js/quansitech/cmf-module-media/cmf-media-hash-worker.js'),
            'spark_md5' => $assetPath('js/quansitech/cmf-module-media/cmf-media-spark-md5.js'),
            'rule' => (string) config('cmf-rich-content.video.upload_rule', 'video'),
            'image_rule' => (string) config('cmf-rich-content.image.upload_rule', 'editor'),
            // 视频规则未单独设上限时按 cmf-media 全局上限，提示文案才不会与实际限制脱钩。
            'max_size' => min(
                (int) config('cmf-media.rules.'.config('cmf-rich-content.video.upload_rule').'.max_size', PHP_INT_MAX),
                (int) config('cmf-media.max_size', 0),
            ),
        ];

        foreach (array_keys((array) config('filament-tinyeditor.profiles', [])) as $profile) {
            // 图片上传是编辑器的基础能力，每个 profile 都要拿到端点；视频页签只出现在挂了
            // cmfvideo 的 profile 上，多下发几个键没有副作用。
            $custom = (array) config("filament-tinyeditor.profiles.{$profile}.custom_configs", []);
            $custom['cmf_media_upload'] = $endpoints;
            config(["filament-tinyeditor.profiles.{$profile}.custom_configs" => $custom]);
        }
    }

    public function boot(): void
    {
        $this->registerPublishing();

        $this->app->booted(function (): void {
            $this->absolutizeExternalPlugins();
            $this->injectMediaUploadEndpoints();

            // Keep the existing vendor asset, including its CSP/SPA rendering logic.
            // Replace only the rendered URL: the upstream vendor mode forces HTTPS.
            if (config('filament-tinyeditor.provider') === 'vendor') {
                foreach (FilamentAsset::getScripts([self::VENDOR_ASSETS]) as $asset) {
                    if ($asset->getId() === 'tinymce') {
                        $asset->html(e($this->versionedAsset('vendor/tinymce/tinymce.min.js')));
                    }
                }
            }

            // TinyEditor's Blade looks up language assets in the vendor namespace.
            FilamentAsset::register([
                Js::make('tinymce-lang-zh-CN', $this->versionedAsset('vendor/cmf-rich-content/langs/zh-CN.js'))->loadedOnRequest(),
                Js::make('cmf-rich-content-lang-en', $this->versionedAsset('vendor/cmf-rich-content/langs/en.js'))->loadedOnRequest(),
            ], self::VENDOR_ASSETS);

            $scripts = ['rich-content-media-upload', 'rich-content-svg', 'rich-content-svg-selection', 'rich-content-tinymce-bridge'];

            FilamentAsset::register(array_map(
                fn (string $name): Js => Js::make($name, $this->versionedAsset("vendor/cmf-rich-content/js/{$name}.js")),
                $scripts,
            ), self::PACKAGE_ASSETS);
        });
    }

    /**
     * 静态资源带上文件修改时间做缓存失效：发布过的资源更新后浏览器仍可能用旧缓存，
     * 会出现「代码已改、页面行为没变」的排查陷阱。
     */
    private function versionedAsset(string $relative): string
    {
        $url = asset($relative);
        $file = public_path($relative);

        if (is_file($file)) {
            $url .= '?v='.filemtime($file);
        }

        return $url;
    }

    /**
     * 配置文件里的外部插件是根相对路径（配置缓存后无法再拼 asset()），
     * 部署在子目录或设置 ASSET_URL 时会 404；启动时统一转成绝对地址。
     */
    private function absolutizeExternalPlugins(): void
    {
        /** @var array<string, mixed> $plugins */
        $plugins = (array) config('filament-tinyeditor.profiles.default.external_plugins', []);

        foreach ($plugins as $name => $path) {
            if (is_string($path) && str_starts_with($path, '/')) {
                $plugins[$name] = $this->versionedAsset(ltrim($path, '/'));
            }
        }

        config(['filament-tinyeditor.profiles.default.external_plugins' => $plugins]);
    }

    /**
     * 前端资源的发布：宿主只跑一条 vendor:publish，不必关心本包内部路径。
     *
     * TinyMCE 发行版来自 tinymce/tinymce（体积大，不入宿主仓库），语言包与插件脚本
     * 随本包发布，保证安装本包即可离线使用。
     *
     * 默认配置不随资源发布，避免宿主误以为需要维护一份拷贝；确实要改时用
     * `vendor:publish --tag=cmf-rich-content-config` 显式拉出再覆盖。
     */
    private function registerPublishing(): void
    {
        /** @var array<string, string> $sources */
        $sources = [
            __DIR__.'/../../resources/css' => public_path('vendor/cmf-rich-content/css'),
            __DIR__.'/../../resources/js' => public_path('vendor/cmf-rich-content/js'),
            __DIR__.'/../../resources/vendor/tinymce-langs' => public_path('vendor/cmf-rich-content/langs'),
        ];

        $tinyMceDist = InstalledVersions::getInstallPath('tinymce/tinymce');

        if ($tinyMceDist !== null && is_dir($tinyMceDist)) {
            $sources[$tinyMceDist] = public_path('vendor/tinymce');
        }

        $this->publishes($sources, self::ASSET_TAG);

        $this->publishes([
            __DIR__.'/../../config/filament-tinyeditor.php' => config_path('filament-tinyeditor.php'),
        ], 'cmf-rich-content-config');
    }
}
