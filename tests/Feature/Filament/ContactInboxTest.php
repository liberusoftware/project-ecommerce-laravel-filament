<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\ContactRequests\ContactRequestResource;
use App\Filament\App\Resources\ContactRequests\Pages\ListContactRequests;
use App\Filament\App\Resources\ContactRequests\Pages\ViewContactRequest;
use App\Models\ContactRequest;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\StoreContext;
use App\Support\TeamPermission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The merchant reads what shoppers sent, and only their own store's; the platform reads every store's. */
class ContactInboxTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    private ContactRequest $mine;

    private ContactRequest $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
        $store = Store::factory()->create(['team_id' => $this->team->id, 'name' => 'Alpha Store']);
        $other = Store::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'Beta Store']);

        $this->mine = $this->message($store, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'subject' => 'Sizing', 'message' => "Does the M run small?\nI am usually an M in tees."]);
        $this->message($store, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test', 'message' => 'Do you ship to Ireland?']);
        $this->theirs = $this->message($other, ['first_name' => 'Eve', 'last_name' => 'Other', 'email' => 'eve@example.test', 'message' => 'Another merchant entirely.']);

        $this->inTeam($this->owner);
    }

    private function message(Store $store, array $attributes): ContactRequest
    {
        return StoreContext::writingTo($store->id, fn () => ContactRequest::create($attributes));
    }

    private function inTeam(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->team);
    }

    private function member(string $role): User
    {
        $member = User::factory()->create();
        $this->team->users()->attach($member, ['role' => $role]);
        $this->inTeam($member);

        return $member;
    }

    public function test_the_inbox_lists_this_stores_messages_with_an_unread_count(): void
    {
        Livewire::test(ListContactRequests::class)
            ->assertCanSeeTableRecords([$this->mine])
            ->assertCanNotSeeTableRecords([$this->theirs])
            ->assertTableColumnExists('full_name')
            ->assertTableColumnExists('email')
            ->assertTableColumnExists('subject')
            ->assertTableColumnExists('created_at')
            ->assertTableColumnExists('is_read')
            ->assertTableColumnStateSet('full_name', 'Ada Lovelace', $this->mine)
            ->assertTableColumnStateSet('is_read', false, $this->mine);

        $this->assertSame('2', ContactRequestResource::getNavigationBadge());
        $this->assertSame('Contact Requests', ContactRequestResource::getNavigationLabel());
    }

    public function test_the_list_searches_first_and_last_names(): void
    {
        Livewire::test(ListContactRequests::class)
            ->searchTable('Lovelace')
            ->assertCanSeeTableRecords([$this->mine])
            ->assertCountTableRecords(1);
    }

    public function test_opening_a_message_shows_all_of_it_and_marks_it_read(): void
    {
        Livewire::test(ViewContactRequest::class, ['record' => $this->mine->getKey()])
            ->assertOk()
            ->assertSee('Ada')
            ->assertSee('Lovelace')
            ->assertSee('ada@example.test')
            ->assertSee('Sizing')
            ->assertSee('I am usually an M in tees.');

        $this->assertNotNull($this->mine->fresh()->read_at);
        $this->assertSame('1', ContactRequestResource::getNavigationBadge());
    }

    public function test_a_message_can_be_marked_read_from_the_list(): void
    {
        Livewire::test(ListContactRequests::class)
            ->assertTableActionVisible('markRead', $this->mine)
            ->callTableAction('markRead', $this->mine)
            ->assertTableActionHidden('markRead', $this->mine);

        $this->assertNotNull($this->mine->fresh()->read_at);
    }

    public function test_marking_read_keeps_the_first_read_time(): void
    {
        $this->mine->forceFill(['read_at' => now()->subDay()])->save();
        $first = $this->mine->fresh()->read_at;

        $this->mine->fresh()->markRead();

        $this->assertTrue($first->equalTo($this->mine->fresh()->read_at));
    }

    public function test_messages_can_be_marked_read_in_bulk_but_only_in_this_store(): void
    {
        $grace = ContactRequest::withoutGlobalScopes()->where('email', 'grace@example.test')->sole();

        Livewire::test(ListContactRequests::class)->callTableBulkAction('markRead', [$this->mine, $grace]);

        $this->assertNotNull($this->mine->fresh()->read_at);
        $this->assertNotNull($grace->fresh()->read_at);
        $this->assertNull($this->theirs->fresh()->read_at);
    }

    public function test_another_stores_message_cannot_be_opened(): void
    {
        $this->get(ContactRequestResource::getUrl('view', ['record' => $this->theirs], panel: 'app', tenant: $this->team))
            ->assertNotFound();

        $this->assertNull($this->theirs->fresh()->read_at);
    }

    public function test_a_viewer_can_read_but_not_mark_read(): void
    {
        $this->member(TeamPermission::ROLE_VIEWER);

        $this->assertTrue(ContactRequestResource::canViewAny());
        $this->assertFalse(ContactRequestResource::canEdit($this->mine));

        Livewire::test(ListContactRequests::class)->assertTableActionHidden('markRead', $this->mine);
        Livewire::test(ViewContactRequest::class, ['record' => $this->mine->getKey()])->assertOk()->assertSee('I am usually an M in tees.');

        $this->assertNull($this->mine->fresh()->read_at);
    }

    public function test_a_catalogue_manager_cannot_read_customer_messages(): void
    {
        $this->member(TeamPermission::ROLE_CATALOGUE);

        $this->assertFalse(ContactRequestResource::canViewAny());
    }

    public function test_the_resource_is_in_both_panels(): void
    {
        $this->assertContains(ContactRequestResource::class, Filament::getPanel('app')->getResources());
        $this->assertContains(ContactRequestResource::class, Filament::getPanel('admin')->getResources());
    }

    public function test_the_platform_sees_every_stores_messages(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $this->actingAs(User::factory()->create()->assignRole('super_admin'));

        $this->get(ContactRequestResource::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('Contact Requests')
            ->assertSee('ada@example.test')
            ->assertSee('eve@example.test')
            ->assertSee('Beta Store');

        $this->get(ContactRequestResource::getUrl('view', ['record' => $this->theirs], panel: 'admin'))
            ->assertOk()
            ->assertSee('Another merchant entirely.');

        $this->assertNotNull($this->theirs->fresh()->read_at);
    }

    public function test_a_platform_permission_to_view_does_not_grant_marking_read(): void
    {
        $user = User::factory()->create();
        foreach (['access_admin_panel', 'view_any_contact::request', 'view_contact::request'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(ContactRequestResource::canViewAny());
        $this->assertTrue(ContactRequestResource::canView($this->theirs));
        $this->assertFalse(ContactRequestResource::canEdit($this->theirs));
    }

    public function test_the_admin_panel_refuses_a_user_without_a_platform_grant(): void
    {
        $this->actingAs($this->owner);

        $this->get(ContactRequestResource::getUrl(panel: 'admin'))->assertForbidden();
    }
}
