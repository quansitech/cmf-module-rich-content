<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Console;

use Illuminate\Console\Command;
use Quansitech\Cmf\RichContent\Providers\TinyMceServiceProvider;

final class InstallRichContent extends Command
{
    protected $signature = 'cmf:rich-content:install';

    protected $description = 'Install or refresh rich-content, TinyMCE and Filament assets';

    public function handle(): int
    {
        $status = $this->call('vendor:publish', ['--tag' => TinyMceServiceProvider::ASSET_TAG, '--force' => true]);

        return $status === self::SUCCESS ? $this->call('filament:assets') : $status;
    }
}
