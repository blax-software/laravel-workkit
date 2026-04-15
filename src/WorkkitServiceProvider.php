<?php

namespace Blax\Workkit;

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
        // 
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
            ]);
        }
    }
}
