<?php

namespace Bayarcash\Laravel;

use Bayarcash\Laravel\Console\ReconcileCommand;
use Bayarcash\Laravel\Contracts\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class BayarcashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bayarcash.php', 'bayarcash');

        $this->app->singleton('bayarcash.manager', function ($app) {
            $config = (array) $app['config']->get('bayarcash');

            $resolverClass = $config['credential_resolver'] ?? null;
            $resolver = $resolverClass ? $app->make($resolverClass) : null;

            return new BayarcashManager($config, $resolver instanceof CredentialResolver ? $resolver : null);
        });

        $this->app->alias('bayarcash.manager', BayarcashManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/bayarcash.php');

        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/bayarcash.php' => config_path('bayarcash.php'),
            ], 'bayarcash-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'bayarcash-migrations');

            $this->publishes([
                __DIR__ . '/../database/migrations/tenant' => database_path('migrations'),
            ], 'bayarcash-tenant-migrations');
        }
    }

    protected function registerSchedule(): void
    {
        if (! config('bayarcash.reconcile.enabled', true)) {
            return;
        }

        $this->app->booted(function () {
            // No-op in the test environment so scheduling never fires there.
            if ($this->app->runningUnitTests()) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);
            $schedule->command('bayarcash:reconcile')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
