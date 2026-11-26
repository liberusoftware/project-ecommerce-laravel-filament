<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Mail\TeamInvitation;
use Livewire\Livewire;
use Tests\TestCase;

/** Adding and inviting team members: who may, and what a bad request is refused for. */
class TeamMembershipActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    }

    private function add(User $by, string $email, ?string $role = TeamPermission::ROLE_VIEWER): void
    {
        app(AddsTeamMembers::class)->add($by, $this->team, $email, $role);
    }

    private function invite(User $by, string $email, ?string $role = TeamPermission::ROLE_VIEWER): void
    {
        app(InvitesTeamMembers::class)->invite($by, $this->team, $email, $role);
    }

    private function refusal(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertSame('addTeamMember', $e->errorBag);

            return $e->errors();
        }

        $this->fail('Expected a validation refusal.');
    }

    public function test_the_owner_adds_a_registered_user_with_a_role(): void
    {
        Event::fake([AddingTeamMember::class, TeamMemberAdded::class]);
        $member = User::factory()->create();

        $this->add($this->owner, $member->email, TeamPermission::ROLE_ORDERS);

        $this->assertSame(TeamPermission::ROLE_ORDERS, $this->team->users()->whereKey($member->id)->sole()->membership->role);
        Event::assertDispatched(AddingTeamMember::class);
        Event::assertDispatched(TeamMemberAdded::class);
    }

    public function test_adding_refuses_an_unknown_address_an_existing_member_and_an_unknown_role(): void
    {
        $member = User::factory()->create();
        $this->team->users()->attach($member, ['role' => TeamPermission::ROLE_VIEWER]);

        $this->assertSame(['email' => ['We were unable to find a registered user with this email address.']], $this->refusal(fn () => $this->add($this->owner, 'nobody@example.test')));
        $this->assertSame(['email' => ['This user already belongs to the team.']], $this->refusal(fn () => $this->add($this->owner, $member->email)));
        $this->assertSame(['email' => ['This user already belongs to the team.']], $this->refusal(fn () => $this->add($this->owner, $this->owner->email)));
        $this->assertArrayHasKey('role', $this->refusal(fn () => $this->add($this->owner, User::factory()->create()->email, 'emperor')));
        $this->assertArrayHasKey('role', $this->refusal(fn () => $this->add($this->owner, User::factory()->create()->email, null)));
    }

    public function test_a_member_without_team_management_cannot_add_or_invite(): void
    {
        $viewer = User::factory()->create();
        $this->team->users()->attach($viewer, ['role' => TeamPermission::ROLE_VIEWER]);

        try {
            $this->add($viewer, User::factory()->create()->email);
            $this->fail('A viewer added a member.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $this->invite($viewer, 'new@example.test');
    }

    public function test_a_team_admin_can_add_members(): void
    {
        $admin = User::factory()->create();
        $this->team->users()->attach($admin, ['role' => TeamPermission::ROLE_ADMIN]);
        $member = User::factory()->create();

        $this->add($admin, $member->email);

        $this->assertTrue($this->team->fresh()->hasUser($member));
    }

    public function test_the_owner_invites_an_address_and_it_is_mailed(): void
    {
        Mail::fake();
        Event::fake([InvitingTeamMember::class]);

        $this->invite($this->owner, 'new@example.test', TeamPermission::ROLE_CATALOGUE);

        $invitation = $this->team->teamInvitations()->sole();
        $this->assertSame(['new@example.test', TeamPermission::ROLE_CATALOGUE], [$invitation->email, $invitation->role]);
        Mail::assertSent(TeamInvitation::class, fn (TeamInvitation $mail) => $mail->hasTo('new@example.test') && $mail->invitation->is($invitation));
        Event::assertDispatched(InvitingTeamMember::class);
    }

    public function test_inviting_refuses_a_repeat_invitation_a_member_a_malformed_address_and_an_unknown_role(): void
    {
        Mail::fake();
        $member = User::factory()->create();
        $this->team->users()->attach($member, ['role' => TeamPermission::ROLE_VIEWER]);
        $this->invite($this->owner, 'new@example.test');

        $this->assertSame(['email' => ['This user has already been invited to the team.']], $this->refusal(fn () => $this->invite($this->owner, 'new@example.test')));
        $this->assertSame(['email' => ['This user already belongs to the team.']], $this->refusal(fn () => $this->invite($this->owner, $member->email)));
        $this->assertArrayHasKey('email', $this->refusal(fn () => $this->invite($this->owner, 'not-an-address')));
        $this->assertArrayHasKey('role', $this->refusal(fn () => $this->invite($this->owner, 'other@example.test', 'emperor')));

        $this->assertSame(1, $this->team->teamInvitations()->count());
        Mail::assertSentCount(1);
    }

    public function test_the_same_address_may_be_invited_to_another_team(): void
    {
        Mail::fake();
        $this->invite($this->owner, 'new@example.test');
        $otherOwner = User::factory()->create();
        $other = Team::factory()->create(['user_id' => $otherOwner->id]);

        app(InvitesTeamMembers::class)->invite($otherOwner, $other, 'new@example.test', TeamPermission::ROLE_VIEWER);

        $this->assertSame(1, $other->teamInvitations()->count());
    }

    public function test_inviting_from_the_team_page_creates_a_tokened_invitation(): void
    {
        Mail::fake();
        $this->actingAs($this->owner);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('addTeamMemberForm', ['email' => 'new@example.test', 'role' => TeamPermission::ROLE_VIEWER])
            ->call('addTeamMember')
            ->assertHasNoErrors();

        $invitation = $this->team->teamInvitations()->sole();
        $this->assertSame(64, strlen($invitation->token));
        Mail::assertSent(TeamInvitation::class);
    }

    public function test_each_invitation_gets_its_own_token(): void
    {
        Mail::fake();
        $this->invite($this->owner, 'one@example.test');
        $this->invite($this->owner, 'two@example.test');

        $this->assertCount(2, $this->team->teamInvitations()->pluck('token')->unique());
    }
}
