<?php

namespace Liberu\Ecommerce\Dropxl\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Dropxl\DropxlCatalog;
use Tests\TestCase;

/** Searching and importing DropXL's catalogue with one store's settings; the core writes the products. */
class DropxlCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected DropxlCatalog $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DropxlCatalog::class)->forSettings(['api_key' => 'test-key', 'api_url' => 'https://api.dropxl.example']);
    }

    private function storeId(): int
    {
        return (int) DB::table('stores')->value('id');
    }

    // ──────────────────────────────────────────
    // searchProducts
    // ──────────────────────────────────────────

    public function test_search_products_returns_success_with_paginated_data()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Widget A', 'price' => 9.99, 'sku' => 'WID-A'],
                ],
                'total' => 1,
                'next_page_url' => null,
            ], 200),
        ]);

        $result = $this->service->searchProducts('widget');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['data']);
        $this->assertEquals('Widget A', $result['data']['data'][0]['name']);
    }

    public function test_search_products_passes_keyword_and_category_params()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response(['data' => []], 200),
        ]);

        $this->service->searchProducts('shoes', 'cat-42', 2, 25);

        Http::assertSent(function ($request) {
            $queryString = parse_url($request->url(), PHP_URL_QUERY);
            parse_str($queryString, $params);

            return ($params['search'] ?? null) === 'shoes'
                && ($params['category_id'] ?? null) === 'cat-42'
                && (int) ($params['page'] ?? 0) === 2
                && (int) ($params['per_page'] ?? 0) === 25;
        });
    }

    public function test_search_products_returns_failure_on_non_200()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response('Unauthorized', 401),
        ]);

        $result = $this->service->searchProducts();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to fetch', $result['message']);
    }

    public function test_search_products_handles_exception()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => function () {
                throw new \Exception('Connection refused');
            },
        ]);

        Log::shouldReceive('error')->once();

        $result = $this->service->searchProducts();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Error', $result['message']);
    }

    // ──────────────────────────────────────────
    // getCategories
    // ──────────────────────────────────────────

    public function test_get_categories_returns_success()
    {
        Http::fake([
            'https://api.dropxl.example/v1/categories*' => Http::response([
                'data' => [
                    ['id' => 'cat-1', 'name' => 'Electronics'],
                    ['id' => 'cat-2', 'name' => 'Clothing'],
                ],
            ], 200),
        ]);

        $result = $this->service->getCategories();

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['data']);
    }

    public function test_get_categories_returns_failure_on_error()
    {
        Http::fake([
            'https://api.dropxl.example/v1/categories*' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->getCategories();

        $this->assertFalse($result['success']);
    }

    // ──────────────────────────────────────────
    // importProduct
    // ──────────────────────────────────────────

    public function test_import_product_creates_product_in_database()
    {
        $dropxlProduct = [
            'name' => 'Test Gadget',
            'description' => 'A great gadget',
            'short_description' => 'Great gadget',
            'price' => 29.99,
            'sku' => 'GAD-001',
            'stock' => 50,
            'category' => null,
            'image_url' => null,
        ];

        $result = $this->service->importProduct($dropxlProduct, $this->storeId());

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('products', [
            'id' => $result['product'],
            'name' => 'Test Gadget',
            'price' => 29.99,
            'supplier_id' => 'dropxl',
            'supplier_product_id' => 'GAD-001',
        ]);
    }

    public function test_import_product_resolves_category()
    {
        $dropxlProduct = [
            'name' => 'Categorized Product',
            'price' => 15.00,
            'stock' => 10,
            'category' => 'Gadgets',
        ];

        $result = $this->service->importProduct($dropxlProduct, $this->storeId());

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('product_categories', ['name' => 'Gadgets']);
        $this->assertNotNull(DB::table('products')->where('id', $result['product'])->value('category_id'));
    }

    public function test_re_importing_a_product_updates_it_rather_than_adding_another()
    {
        $first = $this->service->importProduct(['id' => 7, 'name' => 'Lamp', 'price' => 12], $this->storeId());
        $again = $this->service->importProduct(['id' => 7, 'name' => 'Lamp', 'price' => 12], $this->storeId());

        $this->assertSame($first['product'], $again['product']);
        $this->assertSame(1, DB::table('products')->where('supplier_id', 'dropxl')->where('supplier_product_id', '7')->count());
    }

    // ──────────────────────────────────────────
    // importAll
    // ──────────────────────────────────────────

    public function test_import_all_imports_single_page_of_products()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response([
                'data' => [
                    ['name' => 'Product Alpha', 'price' => 10.00, 'stock' => 5],
                    ['name' => 'Product Beta', 'price' => 20.00, 'stock' => 3],
                ],
                'next_page_url' => null,
            ], 200),
        ]);

        $result = $this->service->importAll($this->storeId());

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['imported']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_import_all_returns_failure_when_api_fails()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response('Bad Request', 400),
        ]);

        $result = $this->service->importAll($this->storeId());

        $this->assertFalse($result['success']);
    }

    // ──────────────────────────────────────────
    // importByCategory
    // ──────────────────────────────────────────

    public function test_import_by_category_passes_category_id_to_api()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response([
                'data' => [
                    ['name' => 'Category Widget', 'price' => 5.00, 'stock' => 20],
                ],
                'next_page_url' => null,
            ], 200),
        ]);

        $result = $this->service->importByCategory($this->storeId(), 'cat-99', 'widget');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['imported']);

        Http::assertSent(function ($request) {
            $queryString = parse_url($request->url(), PHP_URL_QUERY);
            parse_str($queryString, $params);

            return ($params['category_id'] ?? null) === 'cat-99'
                && ($params['search'] ?? null) === 'widget';
        });
    }

    public function test_import_by_category_returns_failure_when_api_fails()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response('Not Found', 404),
        ]);

        $result = $this->service->importByCategory($this->storeId(), 'cat-99');

        $this->assertFalse($result['success']);
    }

    // ──────────────────────────────────────────
    // Authorization header
    // ──────────────────────────────────────────

    public function test_authorization_header_is_included_in_requests()
    {
        Http::fake([
            'https://api.dropxl.example/v1/products*' => Http::response(['data' => []], 200),
        ]);

        $this->service->searchProducts();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }
}
