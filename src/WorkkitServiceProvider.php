<?php

namespace Blax\Workkit;

use Blax\Workkit\Commands\Database\BackupCommand;
use Blax\Workkit\Commands\Database\PruneBackupsCommand;
use Blax\Workkit\Commands\Database\RestoreCommand;
use Blax\Workkit\Commands\PlugNPrayCommand;

class WorkkitServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workkit.php', 'workkit');
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PlugNPrayCommand::class,
                BackupCommand::class,
                RestoreCommand::class,
                PruneBackupsCommand::class,
            ]);

            // Hosts that want to override path / retention publish the
            // config; otherwise mergeConfigFrom() above provides defaults.
            $this->publishes([
                __DIR__ . '/../config/workkit.php' => $this->app->configPath('workkit.php'),
            ], 'workkit-config');
        }
    }
}
