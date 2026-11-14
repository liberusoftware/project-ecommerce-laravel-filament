<?php

namespace Liberu\Ecommerce\Printful\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Contracts\Catalog\ProductImporter;
use Liberu\Ecommerce\Contracts\Modules\ModuleState;
use Liberu\Ecommerce\Printful\PrintfulCatalog;
use Liberu\Ecommerce\Printful\PrintfulDefinition;
use Throwable;

class ImportPrintfulProducts extends Command
{
    protected $signature = 'printful:import-products
                            {--store= : The store to import into; its Printful settings are used}
                            {--product= : One Printful sync product id, rather than the whole store}
                            {--onto= : Map that product onto this existing local product id}';

    protected $description = 'Import a store\'s Printful sync products as made-to-order products with variants';

    public function handle(PrintfulCatalog $catalog, ProductImporter $importer, ModuleState $modules): int
    {
        $storeId = (int) $this->option('store') ?: $modules->currentStoreId();

        if ($storeId === null || ! $modules->isActive(PrintfulDefinition::KEY, $storeId)) {
            $this->warn('Printful is not active for that store; import skipped. Name one with --store.');

            return self::SUCCESS;
        }

        $settings = $modules->settings(PrintfulDefinition::KEY, $storeId);

        if ($id = $this->option('product')) {
            $product = $importer->import($storeId, $catalog->product($id, $settings), $this->option('onto') ? (int) $this->option('onto') : null);
            $this->info("Imported as product {$product}.");

            return self::SUCCESS;
        }

        $imported = $failed = 0;

        foreach ($catalog->productIds($settings) as $id) {
            try {
                $importer->import($storeId, $catalog->product($id, $settings));
                $imported++;
            } catch (Throwable $e) {
                Log::error('Printful product import failed', ['sync_product_id' => $id, 'exception' => $e]);
                $failed++;
            }
        }

        $this->info("Imported {$imported} products; {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
