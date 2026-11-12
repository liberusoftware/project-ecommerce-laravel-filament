<?php

namespace Tests\Feature\Modules;

use App\Services\Fulfilment\FulfilmentProviders;
use App\Support\Modules\TeamModules;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\Dropxl\Filament\DropxlImport;
use Liberu\Ecommerce\Printful\Filament\PrintfulImport;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Tests\Feature\Modules\Concerns\InstallsFixtureModules;
use Tests\TestCase;

/** Printful and DropXL as the core installs them: features that are there only while enabled. */
class FeatureModulesTest extends TestCase
{
    use InstallsFixtureModules;

    private const MODULES = ['ecommerce-dropxl', 'ecommerce-printful'];

    protected function fixtureModulesEnabled(): array
    {
        return $this->name() === 'test_enabled_they_add_their_features_and_menu_entries' ? self::MODULES : [];
    }

    public function test_both_are_installed_with_composer_as_feature_modules_and_off_by_default(): void
    {
        $inventory = app(ModuleInventory::class);

        foreach (self::MODULES as $module) {
            $this->assertTrue(InstalledVersions::isInstalled("liberusoftware/{$module}"));
            $this->assertSame('feature', $inventory->manifests[$module]->toArray()['module_type'] ?? null, "{$module} is not a feature module");
            $this->assertSame('disabled', $inventory->status($module));
        }

        foreach (['module-manager', 'module-manager-filament', 'tenancy', 'users-roles'] as $infrastructure) {
            $this->assertNotSame('feature', $inventory->manifests[$infrastructure]->toArray()['module_type'] ?? null, "{$infrastructure} is offered as a feature");
        }
    }

    public function test_enabled_they_add_their_features_and_menu_entries(): void
    {
        $this->assertEqualsCanonicalizing(['dropxl', 'printful'], array_keys(app(FulfilmentProviders::class)->all()));
        $this->assertNotNull(app(TeamModules::class)->definition('ecommerce-dropxl'));
        $this->assertNotNull(app(TeamModules::class)->definition('ecommerce-printful'));
        $this->assertContains(DropxlImport::class, Filament::getPanel('admin')->getPages());
        $this->assertTrue(Route::has('filament.admin.pages.dropxl-import'));
        $this->assertContains(PrintfulImport::class, Filament::getPanel('admin')->getPages());
        $this->assertTrue(Route::has('filament.admin.pages.printful-import'));
        $this->assertArrayHasKey('printful:import-products', Artisan::all());
    }

    public function test_disabled_they_leave_nothing_behind_but_the_installed_package(): void
    {
        $this->assertSame([], array_keys(app(FulfilmentProviders::class)->all()));
        $this->assertNull(app(TeamModules::class)->definition('ecommerce-dropxl'));
        $this->assertNull(app(TeamModules::class)->definition('ecommerce-printful'));
        $this->assertNotContains(DropxlImport::class, Filament::getPanel('admin')->getPages());
        $this->assertFalse(Route::has('filament.admin.pages.dropxl-import'));
        $this->assertNotContains(PrintfulImport::class, Filament::getPanel('admin')->getPages());
        $this->assertFalse(Route::has('filament.admin.pages.printful-import'));
        $this->assertArrayNotHasKey('printful:import-products', Artisan::all());

        foreach (self::MODULES as $module) {
            $this->assertTrue(InstalledVersions::isInstalled("liberusoftware/{$module}"));
            $this->assertFileExists(base_path("modules/{$module}/module.json"));
        }
    }
}
