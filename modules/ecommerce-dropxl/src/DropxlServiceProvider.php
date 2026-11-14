<?php

namespace Liberu\Ecommerce\Dropxl;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentProvider;
use Liberu\Ecommerce\Contracts\Modules\ModuleDefinition;

class DropxlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dropxl.php', 'dropxl');
        $this->app->tag(DropxlDefinition::class, ModuleDefinition::class);
        $this->app->tag(DropxlFulfilment::class, FulfilmentProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-dropxl');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/dropxl.php' => config_path('dropxl.php')], 'dropxl-config');
        }
    }
}
