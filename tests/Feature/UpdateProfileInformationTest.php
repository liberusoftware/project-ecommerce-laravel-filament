<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Tests\TestCase;

class UpdateProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    private function update(User $user, array $input): array
    {
        try {
            app(UpdatesUserProfileInformation::class)->update($user, $input);
        } catch (ValidationException $e) {
            $this->assertSame('updateProfileInformation', $e->errorBag);

            return $e->errors();
        }

        return [];
    }

    public function test_name_and_email_are_updated(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->update($user, ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']));

        $this->assertSame(['Ada Lovelace', 'ada@example.test'], [$user->fresh()->name, $user->fresh()->email]);
    }

    public function test_keeping_ones_own_address_is_not_a_duplicate(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->update($user, ['name' => 'Renamed', 'email' => $user->email]));
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_another_users_address_and_invalid_input_are_refused_and_nothing_changes(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $other = User::factory()->create();

        $this->assertArrayHasKey('email', $this->update($user, ['name' => 'X', 'email' => $other->email]));
        $this->assertArrayHasKey('email', $this->update($user, ['name' => 'X', 'email' => 'not-an-address']));
        $this->assertArrayHasKey('email', $this->update($user, ['name' => 'X', 'email' => str_repeat('a', 250).'@x.test']));
        $this->assertArrayHasKey('name', $this->update($user, ['name' => '', 'email' => $user->email]));
        $this->assertArrayHasKey('name', $this->update($user, ['name' => str_repeat('a', 256), 'email' => $user->email]));

        $this->assertSame('Original', $user->fresh()->name);
    }
}
