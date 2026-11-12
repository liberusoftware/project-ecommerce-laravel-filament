<?php

namespace Tests\Feature\Modules;

use App\Filament\Admin\Pages\TeamModuleOffers;
use App\Filament\App\Pages\Integrations;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Store;
use App\Models\Team;
use App\Models\TeamModule;
use App\Models\User;
use App\Support\Modules\ModuleActivationRefused;
use App\Support\Modules\TeamModules;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\Contracts\Modules\Abilities;
use Liberu\Ecommerce\Contracts\Modules\ModuleDefinition;
use Liberu\Ecommerce\Contracts\Modules\ModuleNotActive;
use Liberu\Ecommerce\Contracts\Modules\ModuleState;
use Liberu\Ecommerce\Contracts\Modules\ProvidesConfigurationPage;
use Liberu\Ecommerce\FixtureSupplier\Filament\FixturePage;
use Liberu\Ecommerce\FixtureSupplier\FixtureClient;
use Liberu\Ecommerce\FixtureSupplier\FixtureDefinition;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Modules\Concerns\InstallsFixtureModules;
use Tests\TestCase;

/** The acceptance test of the module system: the platform offers a module, teams enable it, their stores inherit it. */
class TeamModuleLifecycleTest extends TestCase
{
    use InstallsFixtureModules;
    use RefreshDatabase;

    private const MODULE = 'fixture-supplier';

    private TeamModules $modules;

    private Team $teamA;

    private Team $teamB;

    private Store $storeA;

    private Store $storeA2;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modules = app(TeamModules::class);
        FixtureDefinition::$events = [];
        $this->teamA = Team::factory()->create();
        $this->teamB = Team::factory()->create();
        $this->storeA = $this->storeOn('a.shop.test', $this->teamA);
        $this->storeA2 = $this->storeOn('a2.shop.test', $this->teamA);
        $this->storeB = $this->storeOn('b.shop.test', $this->teamB);
    }

    private function storeOn(string $host, Team $team): Store
    {
        $store = Store::factory()->create(['team_id' => $team->id]);
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    private function offer(): void
    {
        $this->modules->setAvailable(self::MODULE, true);
    }

    private function activate(Team $team, string $region = 'eu', string $token = 'secret-token'): void
    {
        $this->offer();
        $this->modules->configure(self::MODULE, $team->id, ['api_token' => $token, 'region' => $region]);
        $this->modules->enable(self::MODULE, $team->id);
    }

    private function merchant(string $role = 'admin', ?Team $team = null, ?Team $tenant = null): User
    {
        $team ??= $this->teamA;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => $role]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant ?? $team);

        return $user;
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public function test_it_is_installed_discovered_and_booted_but_off_for_every_team(): void
    {
        $this->assertTrue(app(ModuleInventory::class)->isBooted(self::MODULE));
        $this->assertArrayHasKey(self::MODULE, $this->modules->definitions());
        $this->assertSame('unavailable', $this->modules->status(self::MODULE, $this->teamA->id));
        $this->assertFalse($this->modules->isActive(self::MODULE, $this->storeA->id));
    }

    public function test_its_migrations_are_loaded_with_the_module(): void
    {
        $this->artisan('migrate');

        $this->assertTrue(Schema::hasTable('fixture_supplier_notes'));
    }

    public function test_every_store_of_the_team_inherits_it_and_no_other_teams_store_does(): void
    {
        $this->activate($this->teamA);

        $this->assertTrue($this->modules->isActive(self::MODULE, $this->storeA->id));
        $this->assertTrue($this->modules->isActive(self::MODULE, $this->storeA2->id));
        $this->assertFalse($this->modules->isActive(self::MODULE, $this->storeB->id));
    }

    public function test_a_store_without_a_team_has_no_modules(): void
    {
        $this->activate($this->teamA);
        $orphan = Store::factory()->create(['team_id' => null]);

        $this->assertFalse($this->modules->isActive(self::MODULE, $orphan->id));
    }

    public function test_settings_are_shared_by_a_teams_stores_and_isolated_between_teams(): void
    {
        $this->activate($this->teamA, 'eu', 'token-a');
        $this->activate($this->teamB, 'us', 'token-b');

        $this->assertSame(['api_token' => 'token-a', 'region' => 'eu', 'sandbox' => null], $this->modules->settings(self::MODULE, $this->storeA->id));
        $this->assertSame('token-a', $this->modules->settings(self::MODULE, $this->storeA2->id)['api_token']);
        $this->assertSame('token-b', $this->modules->settings(self::MODULE, $this->storeB->id)['api_token']);

        $this->modules->configure(self::MODULE, $this->teamB->id, ['api_token' => 'token-b2', 'region' => 'us']);
        $this->assertSame('token-a', $this->modules->settings(self::MODULE, $this->storeA->id)['api_token']);
    }

    public function test_its_routes_exist_only_on_the_enabling_teams_stores(): void
    {
        $this->activate($this->teamA);

        $this->get('http://a.shop.test/fixture-supplier/region')->assertOk()->assertJson(['region' => 'eu']);
        $this->get('http://a2.shop.test/fixture-supplier/region')->assertOk()->assertJson(['region' => 'eu']);
        $this->get('http://b.shop.test/fixture-supplier/region')->assertNotFound();
    }

    public function test_its_services_refuse_another_teams_store(): void
    {
        $this->activate($this->teamA);
        $client = app(FixtureClient::class);

        $this->assertSame('eu', $client->regionFor($this->storeA->id));
        $this->expectException(ModuleNotActive::class);
        $client->regionFor($this->storeB->id);
    }

    public function test_disabling_for_one_team_leaves_the_other_working_and_keeps_its_settings(): void
    {
        $this->activate($this->teamA);
        $this->activate($this->teamB, 'us');

        $this->modules->disable(self::MODULE, $this->teamA->id);

        $this->get('http://a.shop.test/fixture-supplier/region')->assertNotFound();
        $this->get('http://b.shop.test/fixture-supplier/region')->assertOk()->assertJson(['region' => 'us']);

        $this->modules->enable(self::MODULE, $this->teamA->id);
        $this->assertSame('eu', $this->modules->settings(self::MODULE, $this->storeA->id)['region']);
        $this->assertSame([
            ['activated', $this->storeA->id], ['activated', $this->storeA2->id],
            ['activated', $this->storeB->id],
            ['deactivated', $this->storeA->id], ['deactivated', $this->storeA2->id],
            ['activated', $this->storeA->id], ['activated', $this->storeA2->id],
        ], FixtureDefinition::$events);
    }

    public function test_a_store_added_to_a_team_using_it_gets_it_and_the_module_is_told(): void
    {
        $this->activate($this->teamA);
        FixtureDefinition::$events = [];

        $added = Store::factory()->create(['team_id' => $this->teamA->id]);

        $this->assertTrue($this->modules->isActive(self::MODULE, $added->id));
        $this->assertSame([['activated', $added->id]], FixtureDefinition::$events);
    }

    public function test_withdrawing_it_turns_it_off_for_every_team_and_keeps_their_settings(): void
    {
        $this->activate($this->teamA);
        $this->activate($this->teamB, 'us');

        $this->modules->setAvailable(self::MODULE, false);

        $this->assertSame('unavailable', $this->modules->status(self::MODULE, $this->teamA->id));
        $this->assertFalse($this->modules->isActive(self::MODULE, $this->storeB->id));
        $this->assertSame(0, TeamModule::query()->where('enabled', true)->count());

        $this->offer();
        $this->assertSame('available', $this->modules->status(self::MODULE, $this->teamA->id));
        $this->modules->enable(self::MODULE, $this->teamB->id);
        $this->assertSame('us', $this->modules->settings(self::MODULE, $this->storeB->id)['region']);
    }

    public function test_a_module_not_offered_cannot_be_enabled_or_configured(): void
    {
        $this->assertRefused(fn () => $this->modules->enable(self::MODULE, $this->teamA->id));
        $this->assertRefused(fn () => $this->modules->configure(self::MODULE, $this->teamA->id, ['api_token' => 'x', 'region' => 'eu']));
    }

    public function test_a_module_that_is_not_installed_cannot_be_offered_or_enabled(): void
    {
        $this->assertRefused(fn () => $this->modules->setAvailable('nothing-installed', true));
        $this->assertRefused(fn () => $this->modules->enable('nothing-installed', $this->teamA->id));
    }

    public function test_it_cannot_be_enabled_until_required_settings_exist(): void
    {
        $this->offer();

        $this->assertRefused(fn () => $this->modules->enable(self::MODULE, $this->teamA->id));
    }

    public function test_configuration_is_validated_by_the_fields_and_by_the_module(): void
    {
        $this->offer();

        $this->assertInvalid(['region' => 'nowhere', 'api_token' => 'x'], 'region');
        $this->assertInvalid(['region' => 'blocked', 'api_token' => 'x'], 'region');
        $this->assertInvalid(['region' => 'eu'], 'api_token');
    }

    public function test_undeclared_settings_are_not_stored(): void
    {
        $this->offer();
        $this->modules->configure(self::MODULE, $this->teamA->id, ['api_token' => 'x', 'region' => 'eu', 'evil' => 'y']);

        $this->assertArrayNotHasKey('evil', TeamModule::query()->sole()->settings);
    }

    public function test_secrets_are_encrypted_at_rest_kept_when_blank_and_never_redisplayed(): void
    {
        $this->activate($this->teamA, 'eu', 'very-secret-token');

        $raw = DB::table('team_modules')->where('team_id', $this->teamA->id)->value('settings');
        $this->assertStringNotContainsString('very-secret-token', (string) $raw);
        $this->assertArrayNotHasKey('settings', TeamModule::query()->first()->toArray());

        $this->modules->configure(self::MODULE, $this->teamA->id, ['api_token' => '', 'region' => 'us']);
        $this->assertSame('very-secret-token', $this->modules->settings(self::MODULE, $this->storeA->id)['api_token']);
        $this->assertArrayNotHasKey('api_token', $this->modules->displayableSettings(self::MODULE, $this->teamA->id));
    }

    public function test_state_does_not_leak_between_requests_in_a_long_lived_worker(): void
    {
        $this->activate($this->teamA);

        // Same application instance throughout, as under Octane; only the host changes.
        $this->get('http://a.shop.test/fixture-supplier/region')->assertOk();
        $this->get('http://b.shop.test/fixture-supplier/region')->assertNotFound();
        $this->get('http://a.shop.test/fixture-supplier/region')->assertOk();

        $first = app(TeamModules::class);
        app()->forgetScopedInstances();
        $this->assertNotSame($first, app(TeamModules::class));
        $this->assertSame(app(ModuleState::class), app(TeamModules::class));
    }

    public function test_the_abilities_follow_the_existing_team_roles_and_never_cross_teams(): void
    {
        $admin = $this->merchant();
        $viewer = $this->merchant('viewer');

        $this->assertTrue($admin->can(Abilities::MANAGE_STORE, $this->storeA->id));
        $this->assertFalse($viewer->can(Abilities::MANAGE_STORE, $this->storeA->id));
        $this->assertFalse($admin->can(Abilities::MANAGE_STORE, $this->storeB->id));
        $this->assertFalse($admin->can(Abilities::MANAGE_STORE, 999999));
    }

    public function test_a_platform_admin_offers_and_withdraws_it_on_the_availability_page(): void
    {
        $this->platformAdmin();

        Livewire::test(TeamModuleOffers::class)
            ->assertCanSeeTableRecords([self::MODULE])
            ->callTableAction('offer', self::MODULE)
            ->assertHasNoTableActionErrors();

        $this->assertTrue(app(TeamModules::class)->isAvailable(self::MODULE));

        app(TeamModules::class)->configure(self::MODULE, $this->teamA->id, ['api_token' => 't', 'region' => 'eu']);
        app(TeamModules::class)->enable(self::MODULE, $this->teamA->id);

        Livewire::test(TeamModuleOffers::class)->callTableAction('withdraw', self::MODULE);
        app()->forgetScopedInstances();

        $this->assertFalse(app(TeamModules::class)->isAvailable(self::MODULE));
        $this->assertFalse(app(TeamModules::class)->isActive(self::MODULE, $this->storeA->id));
    }

    public function test_merchants_and_ordinary_users_cannot_reach_the_platform_pages(): void
    {
        $this->merchant();
        $this->assertFalse(TeamModuleOffers::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(TeamModuleOffers::canAccess());
    }

    public function test_there_is_no_foundation_operations_page(): void
    {
        $this->assertFalse(Route::has('filament.admin.pages.foundation-operations'));
        $this->assertTrue(Route::has('filament.admin.pages.modules'));
    }

    public function test_a_merchant_sees_only_what_is_offered_then_configures_and_enables_it_for_the_team(): void
    {
        $this->merchant();

        Livewire::test(Integrations::class)->assertCanNotSeeTableRecords([self::MODULE]);

        $this->offer();

        Livewire::test(Integrations::class)
            ->assertCanSeeTableRecords([self::MODULE])
            ->assertTableActionVisible('enable', self::MODULE)
            ->callTableAction('enable', self::MODULE);
        $this->assertFalse(app(TeamModules::class)->isActive(self::MODULE, $this->storeA->id), 'no settings yet');

        Livewire::test(Integrations::class)
            ->callTableAction('configure', self::MODULE, data: ['api_token' => 'merchant-token', 'region' => 'eu', 'sandbox' => false])
            ->assertHasNoTableActionErrors()
            ->callTableAction('enable', self::MODULE);
        app()->forgetScopedInstances();

        $this->assertTrue(app(TeamModules::class)->isActive(self::MODULE, $this->storeA->id));
        $this->assertTrue(app(TeamModules::class)->isActive(self::MODULE, $this->storeA2->id));
        $this->assertFalse(app(TeamModules::class)->isActive(self::MODULE, $this->storeB->id));
        $this->assertSame('merchant-token', app(TeamModules::class)->settings(self::MODULE, $this->storeA->id)['api_token']);
    }

    public function test_one_teams_choice_does_not_show_on_another_teams_integrations_page(): void
    {
        $this->activate($this->teamB, 'us', 'token-b');
        $this->merchant();

        Livewire::test(Integrations::class)
            ->assertCanSeeTableRecords([self::MODULE])
            ->assertTableActionVisible('enable', self::MODULE)
            ->mountTableAction('configure', self::MODULE)
            ->assertTableActionDataSet(['region' => null]);
    }

    public function test_a_team_member_without_store_management_cannot_use_the_integrations_page(): void
    {
        $this->merchant('viewer');

        $this->assertFalse(Integrations::canAccess());
    }

    public function test_a_merchant_cannot_manage_another_teams_modules_by_switching_tenant(): void
    {
        $this->offer();
        $this->merchant(tenant: $this->teamB);

        $this->assertFalse(Integrations::canAccess());

        try {
            (new Integrations)->team();
            $this->fail('Expected another team to be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, TeamModule::query()->count());
    }

    public function test_the_modules_own_filament_page_follows_activation_and_authorization(): void
    {
        $this->merchant(team: $this->teamB);
        $this->assertFalse(FixturePage::canAccess());

        $this->activate($this->teamB);
        app()->forgetScopedInstances();
        $this->assertTrue(FixturePage::canAccess());
        $this->assertContains(FixturePage::class, Filament::getPanel('app')->getPages());

        $this->merchant('viewer', $this->teamB);
        $this->assertFalse(FixturePage::canAccess());
    }

    public function test_a_module_can_configure_on_its_own_page_instead_of_the_generated_form(): void
    {
        $definition = new class extends FixtureDefinition implements ProvidesConfigurationPage
        {
            public function key(): string
            {
                return 'fixture-own-page';
            }

            public function configurationUrl(int $teamId): string
            {
                return "https://shop.test/fixture-settings/{$teamId}";
            }
        };
        $this->app->instance('fixture-own-page', $definition);
        $this->app->tag('fixture-own-page', ModuleDefinition::class);
        $this->app->forgetScopedInstances();

        app(TeamModules::class)->setAvailable('fixture-own-page', true);
        $this->merchant();

        Livewire::test(Integrations::class)
            ->assertTableActionHasUrl('configure', "https://shop.test/fixture-settings/{$this->teamA->id}", 'fixture-own-page');
    }

    public function test_a_team_with_several_stores_reaches_its_modules_without_a_current_store(): void
    {
        $this->activate($this->teamA);
        $this->merchant();
        app()->forgetScopedInstances();
        $state = app(ModuleState::class);

        $this->assertNull($state->currentStoreId(), 'No store of a two-store team may be chosen for it.');
        $this->assertSame($this->teamA->id, $state->currentTeamId());
        $this->assertTrue($state->isActiveForTeam(self::MODULE, $this->teamA->id));
        $this->assertSame('secret-token', $state->teamSettings(self::MODULE, $this->teamA->id)['api_token']);
        $this->assertTrue(FixturePage::canAccess());

        $this->merchant('viewer');
        $this->assertFalse(FixturePage::canAccess());
    }

    public function test_team_level_state_is_isolated_between_teams(): void
    {
        $this->activate($this->teamA);
        $state = app(ModuleState::class);

        $this->assertFalse($state->isActiveForTeam(self::MODULE, $this->teamB->id));
        $this->expectException(ModuleNotActive::class);
        $this->expectExceptionMessage("team [{$this->teamB->id}]");
        $state->teamSettings(self::MODULE, $this->teamB->id);
    }

    public function test_a_module_page_is_refused_to_another_teams_merchant(): void
    {
        $this->activate($this->teamA);
        $this->merchant(team: $this->teamB);
        app()->forgetScopedInstances();

        $this->assertSame($this->teamB->id, app(ModuleState::class)->currentTeamId());
        $this->assertFalse(FixturePage::canAccess());
        $this->assertFalse(Gate::allows(Abilities::MANAGE_MODULES, $this->teamA->id));
        $this->assertTrue(Gate::allows(Abilities::MANAGE_MODULES, $this->teamB->id));
        $this->assertFalse(Gate::allows(Abilities::MANAGE_MODULES, 0));
    }

    public function test_the_platform_has_no_current_team(): void
    {
        $this->platformAdmin();

        $this->assertNull(app(ModuleState::class)->currentTeamId());
    }

    public function test_each_action_re_renders_the_row_with_its_new_state(): void
    {
        $this->activate($this->teamA);
        $this->merchant();

        Livewire::test(Integrations::class)
            ->assertTableColumnFormattedStateSet('status', 'On', self::MODULE)
            ->callTableAction('disable', self::MODULE)
            ->assertTableColumnFormattedStateSet('status', 'Off', self::MODULE)
            ->assertTableActionVisible('enable', self::MODULE)
            ->callTableAction('enable', self::MODULE)
            ->assertTableColumnFormattedStateSet('status', 'On', self::MODULE);

        $this->platformAdmin();

        Livewire::test(TeamModuleOffers::class)
            ->assertTableColumnStateSet('teams', 1, self::MODULE)
            ->callTableAction('withdraw', self::MODULE)
            ->assertTableColumnStateSet('availability', 'not offered', self::MODULE)
            ->assertTableColumnStateSet('teams', 0, self::MODULE)
            ->callTableAction('offer', self::MODULE)
            ->assertTableColumnStateSet('availability', 'offered', self::MODULE);
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the module system to refuse this.');
        } catch (ModuleActivationRefused) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertInvalid(array $input, string $field): void
    {
        try {
            $this->modules->configure(self::MODULE, $this->teamA->id, $input);
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
