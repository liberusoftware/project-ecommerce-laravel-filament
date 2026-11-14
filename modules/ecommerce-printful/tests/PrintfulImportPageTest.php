<?php

namespace Liberu\Ecommerce\Printful\Tests;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Liberu\Ecommerce\Printful\Filament\PrintfulImport;
use Liberu\Ecommerce\Printful\PrintfulDefinition;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\ActivatesModules;
use Tests\TestCase;

/** The admin page that lists a store's Printful products and imports one, new or onto an existing product. */
class PrintfulImportPageTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->activateModule(PrintfulDefinition::KEY, ['api_token' => 'pf_test']);
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'])));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Http::fake([
            'api.printful.com/store/products/90' => Http::response(['result' => [
                'sync_product' => ['id' => 90, 'name' => 'Logo Hoodie', 'thumbnail_url' => 'https://files.cdn.printful.com/thumb.png'],
                'sync_variants' => [['id' => 501, 'name' => 'Logo Hoodie / Black / M', 'sku' => 'HD-BLK-M', 'retail_price' => '45.00', 'files' => []]],
            ]]),
            'api.printful.com/store/products*' => Http::response(['result' => [['id' => 90, 'name' => 'Logo Hoodie', 'variants' => 1]]]),
        ]);
    }

    public function test_it_is_in_the_admin_panel_while_printful_is_enabled(): void
    {
        $this->assertContains(PrintfulImport::class, Filament::getPanel('admin')->getPages());
        $this->assertTrue(PrintfulImport::canAccess());
    }

    public function test_it_lists_the_stores_printful_products_and_imports_one(): void
    {
        Livewire::test(PrintfulImport::class)
            ->set('storeId', $this->store->id)
            ->call('loadProducts')
            ->assertSet('hasLoaded', true)
            ->assertSee('Logo Hoodie')
            ->call('importProduct', 90);

        $product = Product::withoutGlobalScopes()->where('store_id', $this->store->id)->where('supplier_product_id', '90')->sole();
        $this->assertSame(['printful', 'Logo Hoodie'], [$product->supplier_id, $product->name]);
    }

    public function test_it_imports_onto_an_existing_product_of_the_store(): void
    {
        $existing = Product::factory()->create(['name' => 'House Hoodie']);
        $existing->forceFill(['store_id' => $this->store->id])->save();

        Livewire::test(PrintfulImport::class)
            ->set('storeId', $this->store->id)
            ->set('onto.90', $existing->id)
            ->call('importProduct', 90);

        $this->assertSame(['printful', '90'], [$existing->fresh()->supplier_id, $existing->fresh()->supplier_product_id]);
    }

    public function test_it_refuses_a_store_without_printful(): void
    {
        $other = Store::factory()->create();

        Livewire::test(PrintfulImport::class)
            ->set('storeId', $other->id)
            ->call('loadProducts')
            ->assertSet('hasLoaded', false);

        Http::assertNothingSent();
    }
}
