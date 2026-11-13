<?php

namespace Liberu\Ecommerce\Printful;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Liberu\Ecommerce\Contracts\Catalog\ImportedProduct;
use Liberu\Ecommerce\Contracts\Catalog\ImportedVariant;
use Liberu\Ecommerce\Contracts\Catalog\SupplierCatalog;

/** A Printful store's sync products, described for import. Made to order, at Printful's retail price. */
class PrintfulCatalog implements SupplierCatalog
{
    public function supplierKey(): string
    {
        return 'printful';
    }

    public function productIds(array $settings): array
    {
        return array_column($this->summaries($settings), 'id');
    }

    /** @return list<array{id: int|string, name: ?string, thumbnail_url: ?string, variants: ?int}> the store's sync products */
    public function summaries(array $settings): array
    {
        $products = [];
        $offset = 0;

        do {
            $page = (array) PrintfulApi::get($settings, '/store/products', ['offset' => $offset, 'limit' => 100])->throw()->json('result', []);
            foreach ($page as $product) {
                $products[] = ['id' => $product['id'], 'name' => $product['name'] ?? null, 'thumbnail_url' => $product['thumbnail_url'] ?? null, 'variants' => $product['variants'] ?? null];
            }
            $offset += count($page);
        } while (count($page) === 100);

        return $products;
    }

    public function product(int|string $supplierProductId, array $settings): ImportedProduct
    {
        $detail = (array) PrintfulApi::get($settings, '/store/products/'.rawurlencode((string) $supplierProductId))->throw()->json('result', []);
        $syncProduct = Arr::get($detail, 'sync_product', []);
        $variants = collect(Arr::get($detail, 'sync_variants', []))->reject(fn ($v) => blank($v['id'] ?? null))->values();
        $name = $syncProduct['name'] ?? "Printful product {$supplierProductId}";
        [$category, $categoryDescription] = $this->categoryFor($name, $variants);

        return new ImportedProduct(
            supplier: 'printful',
            supplierProductId: (string) $supplierProductId,
            name: $name,
            price: (float) ($variants->pluck('retail_price')->filter(fn ($p) => is_numeric($p))->min() ?? 0),
            variants: $this->variants($variants, $name),
            gallery: $variants->flatMap(fn ($v) => $v['files'] ?? [])
                ->filter(fn ($file) => ($file['type'] ?? null) === 'preview' && filled($file['preview_url'] ?? null))
                ->pluck('preview_url')->unique()->values()->all(),
            image: $syncProduct['thumbnail_url'] ?? null,
            categoryName: $category,
            categoryDescription: $categoryDescription,
        );
    }

    /**
     * The first category in `printful.categories` whose keywords appear in the product's or its
     * variants' names. Without rules, imports are left uncategorised.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function categoryFor(string $name, Collection $variants): array
    {
        $text = Str::lower($name.' '.$variants->pluck('name')->implode(' '));

        foreach ((array) config('printful.categories', []) as $category => $rule) {
            if (Str::contains($text, array_map('strtolower', $rule['keywords'] ?? [])) || ($rule['default'] ?? false)) {
                return [$category, $rule['description'] ?? null];
            }
        }

        return [null, null];
    }

    /** @return list<ImportedVariant> */
    private function variants(Collection $variants, string $productName): array
    {
        $parsed = $variants->map(fn (array $v) => ['variant' => $v] + $this->options($v, $productName));
        $axes = collect(['Colour' => 'colour', 'Size' => 'size'])->filter(fn ($key) => $parsed->pluck($key)->filter()->isNotEmpty());

        if ($axes->isEmpty()) {
            $axes = collect(['Style' => 'name']);
            $parsed = $parsed->map(fn ($p) => $p + ['name' => $p['variant']['name'] ?? (string) $p['variant']['id']]);
        }

        return $parsed->map(fn (array $p) => new ImportedVariant(
            (string) $p['variant']['id'],
            $axes->map(fn ($key) => (string) ($p[$key] ?: 'Standard'))->all(),
            (float) ($p['variant']['retail_price'] ?? 0),
            $p['variant']['sku'] ?? $p['variant']['external_id'] ?? null,
        ))->all();
    }

    /** @return array{colour: ?string, size: ?string} from Printful's fields, its named options, or the variant's name */
    private function options(array $variant, string $productName): array
    {
        if (filled($variant['color'] ?? null) || filled($variant['size'] ?? null)) {
            return ['colour' => ($variant['color'] ?? null) ?: null, 'size' => ($variant['size'] ?? null) ?: null];
        }

        $options = collect($variant['options'] ?? [])->mapWithKeys(fn ($o) => [Str::lower((string) ($o['name'] ?? $o['id'] ?? '')) => $o['value'] ?? null]);
        $colour = $options->first(fn ($value, $key) => Str::contains($key, ['color', 'colour']));
        $size = $options->first(fn ($value, $key) => Str::contains($key, 'size'));

        if (! $colour && ! $size) {
            // "Unisex Tee / Black / M" or "Black / M": a leading product name is dropped, then colour and size.
            $parts = array_map('trim', explode('/', (string) ($variant['name'] ?? '')));
            if (count($parts) > 1 && Str::startsWith(Str::lower($productName), Str::lower($parts[0]))) {
                array_shift($parts);
            }
            [$colour, $size] = match (count($parts)) {
                0 => [null, null],
                1 => [null, $parts[0]],
                default => [$parts[count($parts) - 2], $parts[count($parts) - 1]],
            };
        }

        return ['colour' => $colour ?: null, 'size' => $size ?: null];
    }
}
