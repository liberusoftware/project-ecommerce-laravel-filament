<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Tenancy\Concerns\IsTenantModel;
use Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Concerns\TenantAware;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Foundation\Tenancy\TenantState;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class FoundationTenancyContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        Filament::setTenant(null);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        parent::tearDown();
    }

    public function test_explicit_and_panel_context_do_not_leak_across_scopes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        Filament::setTenant($user->currentTeam);
        $this->assertSame($user->currentTeam->id, TenantContext::currentId());
        TenantContext::set(123456);
        $previous = app(TenantState::class);
        $this->assertSame(123456, TenantContext::currentId());
        app()->forgetScopedInstances();
        $this->assertNotSame($previous, app(TenantState::class));
        $this->assertNull(app(TenantState::class)->teamId);
        $this->assertSame(123456, $previous->teamId);
        Filament::setTenant(null);
        $this->assertNull(TenantContext::currentId());
    }

    public function test_unavailable_panel_context_resolves_without_a_team(): void
    {
        Filament::shouldReceive('getTenant')->once()->andThrow(new RuntimeException('No panel context'));
        $this->assertNull(TenantContext::currentId());
    }

    public function test_only_actors_with_a_current_team_relation_resolve_a_team(): void
    {
        $this->assertNull(TenantContext::forUser(null));
        $this->assertNull(TenantContext::forUser(new GenericUser(['id' => 1])));
        $this->assertNull(TenantContext::forUser(new \Illuminate\Foundation\Auth\User));
        $user = User::factory()->withPersonalTeam()->create();
        $this->assertSame($user->currentTeam->id, TenantContext::forUser($user));
        $user->setRelation('currentTeam', null);
        $this->assertNull(TenantContext::forUser($user));
    }

    public function test_request_context_uses_sanctum_and_is_cleared_at_termination(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user, 'sanctum');
        $request = Request::create('/team-contract');
        $middleware = new SetTenantContext;
        $expected = new Response('handled', 202);
        $response = $middleware->handle($request, function (Request $incoming) use ($request, $user, $expected): Response {
            $this->assertSame($request, $incoming);
            $this->assertSame($user->currentTeam->id, TenantContext::currentId());

            return $expected;
        });
        $this->assertSame($expected, $response);
        $middleware->terminate($request, $response);
        $this->assertNull(app(TenantState::class)->teamId);
    }

    #[DataProvider('actorSources')]
    public function test_permission_context_is_resolved_and_cleared(bool $sanctum): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $request = Request::create('/permission-team-contract');
        if ($sanctum) {
            $this->actingAs($user, 'sanctum');
        } else {
            $request->setUserResolver(fn () => $user);
        }
        $middleware = new SetPermissionsTeamContext;
        $response = $middleware->handle($request, function () use ($user): Response {
            $this->assertSame($user->currentTeam->id, app(PermissionRegistrar::class)->getPermissionsTeamId());

            return new Response('permissions');
        });
        $middleware->terminate($request, $response);
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public static function actorSources(): array
    {
        return ['Sanctum actor' => [true], 'request actor' => [false]];
    }

    public function test_guest_request_does_not_inherit_previous_permission_team(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(123);
        (new SetPermissionsTeamContext)->handle(Request::create('/guest'), function (): Response {
            $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());

            return new Response('guest');
        });
    }

    #[DataProvider('jobOutcomes')]
    public function test_queued_job_restores_and_clears_its_submitting_team(bool $fails): void
    {
        TenantContext::set(123);
        $job = new class
        {
            use TenantAware;

            public function __construct()
            {
                $this->captureTenant();
            }
        };
        TenantContext::set(456);
        $this->assertSame(123, $job->tenantId);
        $this->assertInstanceOf(RestoreTenantContext::class, $job->middleware()[0]);
        try {
            $result = $job->middleware()[0]->handle($job, function (object $received) use ($job, $fails): string {
                $this->assertSame($job, $received);
                $this->assertSame(123, TenantContext::currentId());
                if ($fails) {
                    throw new RuntimeException('job failure');
                }

                return 'completed';
            });
            $this->assertFalse($fails, 'The failing job must propagate its exception.');
            $this->assertSame('completed', $result);
        } catch (RuntimeException $exception) {
            $this->assertTrue($fails);
            $this->assertSame('job failure', $exception->getMessage());
        }
        $this->assertNull(TenantContext::currentId());
    }

    public static function jobOutcomes(): array
    {
        return ['successful job' => [false], 'failed job' => [true]];
    }

    public function test_job_without_team_metadata_does_not_inherit_worker_context(): void
    {
        TenantContext::set(123);
        $result = (new RestoreTenantContext)->handle(new stdClass, function (): string {
            $this->assertNull(TenantContext::currentId());

            return 'global job';
        });
        $this->assertSame('global job', $result);
        $this->assertNull(TenantContext::currentId());
    }

    public function test_only_panel_team_events_change_permission_context(): void
    {
        $listener = new SwitchTeam;
        app(PermissionRegistrar::class)->setPermissionsTeamId(123);
        $listener->handle(new stdClass);
        $this->assertSame(123, app(PermissionRegistrar::class)->getPermissionsTeamId());
        $user = User::factory()->withPersonalTeam()->create();
        $listener->handle(new TenantSet($user->currentTeam, $user));
        $this->assertSame($user->currentTeam->id, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_model_queries_and_default_ownership_respect_the_selected_team(): void
    {
        Schema::create('tenant_contract_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
        });
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $otherTeam = $other->currentTeam;
        TenantContext::set($team->id);
        $own = TenancyContractRecord::create(['name' => 'own record']);
        $foreign = TenancyContractRecord::create(['name' => 'foreign record', 'team_id' => $otherTeam->id]);
        $this->assertSame($team->id, $own->team_id);
        $this->assertSame($otherTeam->id, $foreign->team_id);
        $this->assertSame(['own record'], TenancyContractRecord::pluck('name')->all());
        $this->assertSame($team->id, $own->team->id);
        $this->assertTrue($own->belongsToTeam($team->id));
        $this->assertFalse($own->belongsToTeam($otherTeam->id));
        $this->assertTrue($own->belongsToTeam(null));
        TenantContext::clear();
        $this->assertSame(['foreign record'], TenancyContractRecord::byTeam($otherTeam->id)->pluck('name')->all());
        $this->assertSame(2, TenancyContractRecord::byTeam(null)->count());
        $this->assertNull(TenancyContractRecord::create(['name' => 'global record'])->team_id);
    }
}

final class TenancyContractRecord extends Model
{
    use IsTenantModel;

    protected $table = 'tenant_contract_records';

    protected $guarded = [];

    public $timestamps = false;
}
