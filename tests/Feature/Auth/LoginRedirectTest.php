<?php

namespace Tests\Feature\Auth;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Where signing in, signing up and signing out land each kind of user. */
class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, array $headers = [])
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password'], $headers);
    }

    public function test_a_shopper_lands_on_the_storefront(): void
    {
        $this->login(User::factory()->create())->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_a_shopper_returns_to_where_they_were_going(): void
    {
        $this->withSession(['url.intended' => url('/checkout')]);

        $this->login(User::factory()->create())->assertRedirect(url('/checkout'));
    }

    public function test_a_team_member_lands_on_the_merchant_panel(): void
    {
        $member = User::factory()->create();
        Team::factory()->create()->users()->attach($member, ['role' => 'viewer']);

        $this->login($member)->assertRedirect('/app');
    }

    public function test_a_team_owner_lands_on_the_merchant_panel(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $this->login($owner)->assertRedirect('/app');
        $this->assertSame($team->id, getPermissionsTeamId());
    }

    /** @return array<string, array{string}> */
    public static function platformRoles(): array
    {
        return ['super admin' => ['super_admin'], 'admin' => ['admin']];
    }

    #[DataProvider('platformRoles')]
    public function test_a_platform_role_lands_on_the_admin_panel_ahead_of_any_team(string $role): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        Team::factory()->create()->users()->attach($admin, ['role' => 'viewer']);

        $this->login($admin)->assertRedirect('/admin');
    }

    public function test_a_json_login_answers_without_a_redirect(): void
    {
        $this->login(User::factory()->create(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertExactJson(['two_factor' => false]);
    }

    public function test_a_new_registrant_is_a_shopper_and_lands_on_the_storefront(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'terms' => 'on',
        ])->assertRedirect('/');

        $user = User::query()->where('email', 'ada@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->allTeams()->isEmpty());
        $this->assertSame([], $user->getRoleNames()->all());
    }

    public function test_a_json_registration_answers_without_a_redirect(): void
    {
        $this->postJson('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'terms' => 'on',
        ])->assertOk()->assertExactJson(['two_factor' => false]);
    }

    public function test_signing_out_of_a_panel_returns_to_the_login_page(): void
    {
        $owner = User::factory()->create();
        Team::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->post(route('filament.app.auth.logout'))->assertRedirect('/login');

        $this->assertGuest();
    }
}
