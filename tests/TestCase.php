<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected Application $app;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $files = new Filesystem;
        $this->fixture = sys_get_temp_dir().'/cmf-rich-content-test-'.bin2hex(random_bytes(12));
        $files->makeDirectory($this->fixture, 0700);
        $files->ensureDirectoryExists($this->fixture.'/bootstrap/cache');
        $files->ensureDirectoryExists($this->fixture.'/app');
        $files->put($this->fixture.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->fixture.'/discovery/composer');
        $files->ensureDirectoryExists($this->fixture.'/storage/framework/views');

        // Boot a new host; never read or modify a real application's config, cache or public files.
        $this->app = new class($this->fixture) extends Application
        {
            protected function normalizeCachePath($key, $default)
            {
                return $this->bootstrapPath($default);
            }
        };
        (new ApplicationBuilder($this->app))->withKernels()->withProviders();
        $this->app->useStoragePath($this->fixture.'/storage');
        $this->app->instance('request', Request::create('http://rich-content.test'));

        $manifest = new PackageManifest($files, $this->fixture, $this->app->getCachedPackagesPath());
        $manifest->vendorPath = $this->fixture.'/discovery';
        $files->put($manifest->vendorPath.'/composer/installed.json', json_encode([
            'packages' => $this->discoveryPackages(),
        ], JSON_THROW_ON_ERROR));
        $this->app->instance(PackageManifest::class, $manifest);

        $this->app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app->instance('env', 'testing');
            $app['config']->set([
                'app.env' => 'testing',
                'app.url' => 'http://rich-content.test',
                'app.asset_url' => null,
                'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
                'cache.default' => 'array',
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null,
                'filesystems.default' => 'local',
                'queue.default' => 'sync',
                'session.driver' => 'array',
                'cmf-core.apply_defaults' => false,
                'cmf-media.default' => 'local',
            ]);
        });

        $this->app->bootstrapWith([LoadConfiguration::class, RegisterFacades::class, RegisterProviders::class, BootProviders::class]);
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    protected function tearDown(): void
    {
        try {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            if (isset($this->app)) {
                $this->app->flush();
                Application::setInstance(null);
            }
            if (isset($this->fixture)) {
                (new Filesystem)->deleteDirectory($this->fixture);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return list<array<string, mixed>> */
    private function discoveryPackages(): array
    {
        $packages = [];

        // Resolve the real providers from Composer metadata, not from a hand-written list of provider classes.
        foreach ([
            'blade-ui-kit/blade-icons',
            'blade-ui-kit/blade-heroicons',
            'ryangjchandler/blade-capture-directive',
            'filament/support',
            'filament/actions',
            'filament/schemas',
            'filament/forms',
            'filament/infolists',
            'filament/tables',
            'filament/notifications',
            'filament/widgets',
            'filament/filament',
            'livewire/livewire',
            'quansitech/cmf-core',
            'quansitech/cmf-module-media',
            'amidesfahani/filament-tinyeditor',
        ] as $package) {
            $packages[] = json_decode(file_get_contents(InstalledVersions::getInstallPath($package).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        }

        $packages[] = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        return $packages;
    }
}
