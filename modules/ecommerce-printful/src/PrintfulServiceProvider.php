<?php

namespace Liberu\Ecommerce\Printful;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentProvider;
use Liberu\Ecommerce\Contracts\Modules\ModuleDefinition;
use Liberu\Ecommerce\Printful\Console\ImportPrintfulProducts;

class PrintfulServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/printful.php', 'printful');
        $this->app->tag(PrintfulDefinition::class, ModuleDefinition::class);
        $this->app->tag(PrintfulFulfilment::class, FulfilmentProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-printful');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPrintfulProducts::class]);
            $this->publishes([__DIR__.'/../config/printful.php' => config_path('printful.php')], 'printful-config');
        }
    }
}
