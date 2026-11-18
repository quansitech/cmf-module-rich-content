<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Feature;

use AmidEsfahani\FilamentTinyEditor\TinyeditorServiceProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use Quansitech\Cmf\RichContent\RichContentServiceProvider;
use Quansitech\Cmf\RichContent\Tests\TestCase;

final class PackageIntegrationTest extends TestCase
{
    private const VENDOR_ASSETS = 'amidesfahani/filament-tinyeditor';

    private const RICH_CONTENT_ASSETS = 'quansitech/cmf-module-rich-content';

    public function test_package_discovery_keeps_vendor_provider_and_authenticates_editor_routes(): void
    {
        $this->assertInstanceOf(TinyeditorServiceProvider::class, $this->app->getProvider(TinyeditorServiceProvider::class));
        $this->assertInstanceOf(RichContentServiceProvider::class, $this->app->getProvider(RichContentServiceProvider::class));

        foreach (['wechat-article', 'image', 'video'] as $name) {
            $route = $this->app['router']->getRoutes()->getByName('cmf-rich-content.'.$name);
            $this->assertNotNull($route);
            $this->assertSame('cmf-rich-content/'.$name, $route->uri());
            $this->assertSame(['POST'], $route->methods());
            $this->assertContains('web', $route->middleware());
            $this->assertContains('auth', $route->middleware());
            $this->assertContains('throttle:cmf-rich-content-'.$name, $route->middleware());
        }
    }

    public function test_single_vendor_tinymce_script_renders_the_local_http_url(): void
    {
        $this->assertSame('vendor', config('filament-tinyeditor.provider'));
        $scripts = array_values(array_filter(FilamentAsset::getScripts(), static fn (Js $asset): bool => $asset->getId() === 'tinymce'));
        $vendor = array_values(array_filter(FilamentAsset::getScripts([self::VENDOR_ASSETS]), static fn (Js $asset): bool => $asset->getId() === 'tinymce'));

        $this->assertCount(1, $scripts);
        $this->assertSame($vendor, $scripts);
        // The public override affects rendered HTML, not the internal source path.
        $html = $scripts[0]->getHtml()->toHtml();
        $this->assertMatchesRegularExpression('~<script\s+src="http://rich-content\.test/vendor/tinymce/tinymce\.min\.js(?:\?v=\d+)?"~', $html);
    }

    public function test_editor_defaults_reference_local_plugins_and_separately_registered_scripts(): void
    {
        $profile = config('filament-tinyeditor.profiles.default');
        $this->assertStringContainsString('wxarticle', $profile['toolbar']);
        $this->assertStringStartsWith(asset('vendor/cmf-rich-content/js/tinymce-wechat-article.js'), $profile['external_plugins']['wechatarticle']);
        $this->assertSame('+body[svg]', $profile['custom_configs']['valid_children']);
        $this->assertStringContainsString('animate[', $profile['custom_configs']['extended_valid_elements']);
        $this->assertSame('/cmf-rich-content/image', $profile['custom_configs']['cmf_media_upload']['image_fallback']);

        foreach (['rich-content-media-upload', 'rich-content-svg', 'rich-content-svg-selection', 'rich-content-tinymce-bridge'] as $id) {
            $this->assertStringStartsWith(asset('vendor/cmf-rich-content/js/'.$id.'.js'), FilamentAsset::getScriptSrc($id, package: self::RICH_CONTENT_ASSETS));
        }
        $this->assertStringStartsWith(asset('vendor/cmf-rich-content/langs/zh-CN.js'), FilamentAsset::getScriptSrc('tinymce-lang-zh-CN', package: self::VENDOR_ASSETS));
    }

    public function test_frontend_component_sanitizes_content_and_loads_its_assets(): void
    {
        $html = Blade::render(
            '<x-cmf-rich-content::body :html="$content" />',
            ['content' => '<p onclick="bad()">正文</p><script>bad()</script>'],
        );

        $this->assertStringContainsString('cmf-rich-content-body', $html);
        $this->assertStringContainsString('<p>正文</p>', $html);
        $this->assertStringNotContainsString('bad()', $html);
        $this->assertStringContainsString('vendor/cmf-rich-content/css/body.css', $html);
        $this->assertStringContainsString('vendor/cmf-rich-content/js/rich-content-svg.js', $html);
    }

    public function test_single_install_command_publishes_vendor_and_package_assets(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('cmf:rich-content:install'), $kernel->output());

        foreach ([
            'vendor/tinymce/tinymce.min.js',
            'vendor/tinymce/skins/ui/oxide/skin.min.css',
            'vendor/cmf-rich-content/css/body.css',
            'vendor/cmf-rich-content/langs/zh-CN.js',
            'vendor/cmf-rich-content/js/tinymce-wechat-article.js',
            'vendor/cmf-rich-content/js/tinymce-video.js',
            'vendor/cmf-rich-content/js/rich-content-tinymce-bridge.js',
            'js/amidesfahani/filament-tinyeditor/components/tinyeditor.js',
            'js/quansitech/cmf-module-media/cmf-media-hash-worker.js',
            'js/quansitech/cmf-module-media/cmf-media-spark-md5.js',
        ] as $path) {
            $this->assertFileExists(public_path($path));
        }
    }
}
