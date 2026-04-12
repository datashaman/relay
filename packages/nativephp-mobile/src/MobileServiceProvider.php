<?php

namespace Native\Mobile;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Commands\MobileBuildCommand;
use Native\Mobile\Commands\MobileServeCommand;

class MobileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nativephp-mobile.php', 'nativephp-mobile');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nativephp-mobile.php' => config_path('nativephp-mobile.php'),
            ], 'nativephp-mobile-config');

            $this->commands([
                MobileBuildCommand::class,
                MobileServeCommand::class,
            ]);
        }
    }
}
