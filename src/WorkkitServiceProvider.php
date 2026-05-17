<?php

namespace Blax\Workkit;

use Blax\Workkit\Attributes\VariablePaginatable;
use Blax\Workkit\Commands\Database\BackupCommand;
use Blax\Workkit\Commands\Database\PruneBackupsCommand;
use Blax\Workkit\Commands\Database\RestoreCommand;
use Blax\Workkit\Commands\PlugNPrayCommand;
use Illuminate\Http\Request;
use ReflectionException;
use ReflectionMethod;

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
        $this->registerPerPageMacro();

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

    /**
     * Resolve the effective page size for the current route via the
     * {@see VariablePaginatable} attribute on the controller method.
     *
     * Order of resolution:
     *   1. No route / closure action / no attribute  → $fallback (default 15)
     *   2. Attribute present, allowUserOverride=true → clamp `?per_page=N`
     *      into `[1, max]`, defaulting to `default` when the query is missing.
     *   3. Attribute present, allowUserOverride=false → `default` (ignores query).
     */
    private function registerPerPageMacro(): void
    {
        Request::macro('perPage', function (int $fallback = 15): int {
            /** @var Request $this */
            $route = $this->route();
            $controller = is_object($route?->getController()) ? $route->getController()::class : null;
            $action = is_string($route?->getActionMethod()) ? $route->getActionMethod() : null;

            if (! $controller || ! $action) {
                return $fallback;
            }

            try {
                $reflection = new ReflectionMethod($controller, $action);
            } catch (ReflectionException) {
                return $fallback;
            }

            $attributes = $reflection->getAttributes(VariablePaginatable::class);
            if ($attributes === []) {
                return $fallback;
            }

            /** @var VariablePaginatable $config */
            $config = $attributes[0]->newInstance();

            if (! $config->allowUserOverride) {
                return $config->default;
            }

            $requested = (int) $this->query('per_page', (string) $config->default);

            return min(max($requested, 1), $config->max);
        });
    }
}
