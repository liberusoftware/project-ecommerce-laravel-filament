<?php

namespace Tests\Feature\Modules;

use App\Models\Store;
use App\Models\Team;
use App\Support\Modules\TeamModules;
use Database\Seeders\TeamModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Concerns\InstallsFixtureModules;
use Tests\TestCase;

class TeamModulesSeederTest extends TestCase
{
    use InstallsFixtureModules;
    use RefreshDatabase;

    private const MODULE = 'fixture-supplier';

    public function test_it_seeds_nothing_when_the_site_names_no_module(): void
    {
        config(['team-modules.seed' => [], 'team-modules.store_commands' => []]);

        $this->seed(TeamModulesSeeder::class);

        $this->assertSame(0, DB::table('module_activations')->count());
        $this->assertFalse(app(TeamModules::class)->isAvailable(self::MODULE));
    }

    public function test_a_named_module_is_enabled_offered_and_active_for_every_team_with_its_settings(): void
    {
        $teams = Team::factory()->count(2)->create();
        config(['team-modules.seed' => [self::MODULE => ['api_token' => 'fx-token', 'region' => 'eu']]]);

        $this->seed(TeamModulesSeeder::class);
        app()->forgetScopedInstances();
        $modules = app(TeamModules::class);

        $this->assertTrue((bool) DB::table('module_activations')->where('module', self::MODULE)->value('enabled'));
        $this->assertTrue($modules->isAvailable(self::MODULE));
        foreach ($teams as $team) {
            $this->assertTrue($modules->isActiveForTeam(self::MODULE, $team->id));
            $this->assertSame('fx-token', $modules->teamSettings(self::MODULE, $team->id)['api_token']);
        }
    }

    public function test_without_its_required_settings_it_is_offered_but_not_switched_on(): void
    {
        $team = Team::factory()->create();
        config(['team-modules.seed' => [self::MODULE => ['api_token' => '', 'region' => null]]]);

        $this->seed(TeamModulesSeeder::class);
        app()->forgetScopedInstances();

        $this->assertTrue(app(TeamModules::class)->isAvailable(self::MODULE));
        $this->assertFalse(app(TeamModules::class)->isActiveForTeam(self::MODULE, $team->id));
    }

    public function test_store_commands_run_once_per_store_and_unknown_ones_are_skipped(): void
    {
        $stores = Store::factory()->count(2)->create();
        $seen = [];
        Artisan::command('fixture:per-store {--store=}', function () use (&$seen) {
            $seen[] = (int) $this->option('store');
        });
        config(['team-modules.store_commands' => ['fixture:per-store', 'not:installed']]);

        $this->seed(TeamModulesSeeder::class);

        $this->assertEqualsCanonicalizing(Store::query()->pluck('id')->all(), $seen);
        $this->assertContains($stores->first()->id, $seen);
    }
}
