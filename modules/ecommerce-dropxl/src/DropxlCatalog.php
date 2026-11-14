<?php

namespace Liberu\Ecommerce\Dropxl;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Liberu\Ecommerce\Contracts\Catalog\ImportedProduct;
use Liberu\Ecommerce\Contracts\Catalog\ProductImporter;
use Throwable;

/** DropXL's catalogue, searched and imported with one store's settings. The core writes the products. */
class DropxlCatalog
{
    /** @var array<string, mixed> */
    private array $settings = [];

    public function __construct(private readonly ProductImporter $importer) {}

    /** @param  array<string, mixed>  $settings  one store's DropXL settings */
    public function forSettings(array $settings): self
    {
        $catalog = clone $this;
        $catalog->settings = $settings;

        return $catalog;
    }

    /** @return array{success: bool, data?: mixed, message?: string, error?: string} */
    public function searchProducts(?string $keyword = null, ?string $categoryId = null, int $page = 1, int $perPage = 20): array
    {
        $query = array_filter(['page' => $page, 'per_page' => $perPage, 'search' => $keyword, 'category_id' => $categoryId]);

        return $this->fetch('/v1/products', $query, 'products');
    }

    /** @return array{success: bool, data?: mixed, message?: string, error?: string} */
    public function getCategories(): array
    {
        return $this->fetch('/v1/categories', [], 'categories');
    }

    /**
     * @param  array<string, mixed>  $product  one product as DropXL returns it
     * @return array{success: bool, product?: int, message?: string}
     */
    public function importProduct(array $product, int $storeId): array
    {
        try {
            return ['success' => true, 'product' => $this->importer->import($storeId, $this->describe($product))];
        } catch (Throwable $e) {
            Log::error('DropXL import product failed: '.$e->getMessage());

            return ['success' => false, 'message' => 'Error importing product: '.$e->getMessage()];
        }
    }

    /** @return array{success: bool, imported: int, failed: int, message?: string} */
    public function importAll(int $storeId, ?string $keyword = null): array
    {
        return $this->importPages($storeId, $keyword, null);
    }

    /** @return array{success: bool, imported: int, failed: int, message?: string} */
    public function importByCategory(int $storeId, string $categoryId, ?string $keyword = null): array
    {
        return $this->importPages($storeId, $keyword, $categoryId);
    }

    /** @param  array<string, mixed>  $product */
    public function describe(array $product): ImportedProduct
    {
        $name = (string) ($product['name'] ?? 'DropXL product');

        return new ImportedProduct(
            supplier: 'dropxl',
            supplierProductId: (string) ($product['id'] ?? $product['sku'] ?? Str::slug($name)),
            name: $name,
            price: (float) ($product['price'] ?? 0),
            image: $product['image_url'] ?? $product['image'] ?? null,
            categoryName: filled($product['category'] ?? null) ? (string) $product['category'] : null,
            categoryDescription: 'Imported from DropXL',
            description: $product['description'] ?? null,
        );
    }

    /** @return array{success: bool, imported: int, failed: int, message?: string} */
    private function importPages(int $storeId, ?string $keyword, ?string $categoryId): array
    {
        $imported = $failed = 0;
        $page = 1;

        do {
            $result = $this->searchProducts($keyword, $categoryId, $page, 50);

            if (! $result['success']) {
                return ['success' => false, 'message' => $result['message'], 'imported' => $imported, 'failed' => $failed];
            }

            $products = self::products($result['data']);

            foreach ($products as $product) {
                $this->importProduct($product, $storeId)['success'] ? $imported++ : $failed++;
            }

            $page++;
        } while ($products !== [] && filled($result['data']['next_page_url'] ?? null));

        return ['success' => true, 'imported' => $imported, 'failed' => $failed];
    }

    /** @return array{success: bool, data?: mixed, message?: string, error?: string} */
    private function fetch(string $path, array $query, string $what): array
    {
        try {
            $response = DropxlApi::get($this->settings, $path, $query);
        } catch (Throwable $e) {
            Log::error("DropXL {$what} request failed: ".$e->getMessage());

            return ['success' => false, 'message' => "Error fetching {$what} from DropXL", 'error' => $e->getMessage()];
        }

        return $response->successful()
            ? ['success' => true, 'data' => $response->json()]
            : ['success' => false, 'message' => "Failed to fetch {$what} from DropXL", 'error' => $response->body()];
    }

    /** The products in a response, paginated or a plain list. */
    public static function products(mixed $data): array
    {
        return match (true) {
            is_array($data['data'] ?? null) => $data['data'],
            is_array($data['products'] ?? null) => $data['products'],
            is_array($data) && array_is_list($data) => $data,
            default => [],
        };
    }
}
