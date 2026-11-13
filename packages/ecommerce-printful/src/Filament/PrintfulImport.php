<?php

namespace Liberu\Ecommerce\Printful\Filament;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Contracts\Catalog\ProductDirectory;
use Liberu\Ecommerce\Contracts\Catalog\ProductImporter;
use Liberu\Ecommerce\Contracts\Modules\Abilities;
use Liberu\Ecommerce\Contracts\Modules\ModuleState;
use Liberu\Ecommerce\Contracts\Stores\StoreDirectory;
use Liberu\Ecommerce\Printful\PrintfulCatalog;
use Liberu\Ecommerce\Printful\PrintfulDefinition;
use Throwable;

/** Browse a store's Printful products and import one, new or onto an existing product. */
class PrintfulImport extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected string $view = 'ecommerce-printful::printful-import';

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = 'Dropshipping';

    protected static ?string $title = 'Printful Import';

    protected static ?string $navigationLabel = 'Import from Printful';

    /** @var list<array<string, mixed>> */
    public array $syncProducts = [];

    public bool $hasLoaded = false;

    public ?int $storeId = null;

    /** Local product id to import onto, keyed by Printful sync product id; empty means a new product. */
    public array $onto = [];

    public static function canAccess(): bool
    {
        if (auth()->user() === null) {
            return false;
        }

        foreach (array_keys(app(StoreDirectory::class)->names()) as $storeId) {
            if (Gate::allows(Abilities::MANAGE_CATALOGUE, $storeId)) {
                return true;
            }
        }

        return false;
    }

    public function mount(): void
    {
        $stores = $this->stores();
        $this->storeId = count($stores) === 1 ? array_key_first($stores) : null;
    }

    /** @return array<int, string> the stores whose catalogue this user manages */
    public function stores(): array
    {
        return array_filter(app(StoreDirectory::class)->names(), fn (int $storeId) => Gate::allows(Abilities::MANAGE_CATALOGUE, $storeId), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<int, string> */
    public function localProducts(): array
    {
        return $this->storeId !== null && array_key_exists($this->storeId, $this->stores())
            ? app(ProductDirectory::class)->names($this->storeId)
            : [];
    }

    public function updatedStoreId(): void
    {
        $this->syncProducts = [];
        $this->hasLoaded = false;
        $this->onto = [];
    }

    public function loadProducts(PrintfulCatalog $catalog): void
    {
        if (($settings = $this->settings()) === null) {
            return;
        }

        try {
            $this->syncProducts = $catalog->summaries($settings);
            $this->hasLoaded = true;
            Notification::make()->title('Loaded '.count($this->syncProducts).' Printful product(s)')->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Printful did not answer')->body($e->getMessage())->danger()->send();
        }
    }

    public function importProduct(int|string $syncProductId, PrintfulCatalog $catalog, ProductImporter $importer): void
    {
        if (($settings = $this->settings()) === null) {
            return;
        }

        $onto = filled($this->onto[$syncProductId] ?? null) ? (int) $this->onto[$syncProductId] : null;

        if ($onto !== null && ! array_key_exists($onto, $this->localProducts())) {
            Notification::make()->title('Choose a product of this store to import onto')->danger()->send();

            return;
        }

        try {
            $imported = $catalog->product($syncProductId, $settings);
            $importer->import($this->storeId, $imported, $onto);
            Notification::make()->title("Imported \"{$imported->name}\"")->body(count($imported->variants).' variants mapped to Printful.')->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();
        }
    }

    /** The chosen store's Printful settings; null, with a notice, when there is no such store or Printful is off for it. */
    private function settings(): ?array
    {
        if ($this->storeId === null || ! array_key_exists($this->storeId, $this->stores())) {
            Notification::make()->title('Choose the store to import into')->warning()->send();

            return null;
        }

        $modules = app(ModuleState::class);

        if (! $modules->isActive(PrintfulDefinition::KEY, $this->storeId)) {
            Notification::make()->title('Printful is not active for this store')->danger()->send();

            return null;
        }

        return $modules->settings(PrintfulDefinition::KEY, $this->storeId);
    }
}
