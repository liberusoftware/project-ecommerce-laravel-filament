<?php

namespace App\Providers;

use App\Console\Commands\Modules\ThemeListCommand;
use App\Console\Commands\Modules\ThemeValidateCommand;
use App\Models\Store;
use App\Services\Fulfilment\FulfilmentProviders;
use App\Services\Fulfilment\SupplierProductImporter;
use App\Support\Modules\Products;
use App\Support\Modules\Stores;
use App\Support\Modules\TeamModules;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Contracts\Catalog\ProductDirectory;
use Liberu\Ecommerce\Contracts\Catalog\ProductImporter;
use Liberu\Ecommerce\Contracts\Modules\ModuleState;
use Liberu\Ecommerce\Contracts\Stores\StoreDirectory;

/**
 * The host's side of modules: team activation and the contracts modules plug into. The
 * manager's provider, registered next in config/app.php, registers the modules. See ADR 0018.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TeamModules::class);
        $this->app->alias(TeamModules::class, ModuleState::class);
        $this->app->scoped(FulfilmentProviders::class);
        $this->app->bind(ProductImporter::class, SupplierProductImporter::class);
        $this->app->bind(StoreDirectory::class, Stores::class);
        $this->app->bind(ProductDirectory::class, Products::class);
    }

    public function boot(): void
    {
        Store::created(fn (Store $store) => $this->app->make(TeamModules::class)->storeJoined($store));

        if ($this->app->runningInConsole()) {
            $this->commands([ThemeListCommand::class, ThemeValidateCommand::class]);
        }
    }
}
