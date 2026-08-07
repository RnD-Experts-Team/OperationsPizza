<?php

namespace Tests\Feature\EventConsume;

use App\Models\User;
use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserDeletedHandler;
use App\Services\EventConsume\Handlers\UserUpdatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserReplicationTest extends TestCase
{
    use RefreshDatabase;

    /** The real auth.v1.user.created payload from pizzasys UserManagementService. */
    private function createdEvent(array $overrides = []): array
    {
        return [
            'data' => [
                'user' => array_merge([
                    'id' => 7,
                    'name' => 'Dana Ruiz',
                    'email' => 'dana@example.com',
                    'email_verified_at' => null,
                    'created_at' => '2026-08-01T00:00:00+00:00',
                    'updated_at' => '2026-08-01T00:00:00+00:00',
                ], $overrides),
                'roles' => ['Store Manager'],
                'permissions_direct' => [],
            ],
        ];
    }

    public function test_it_replicates_a_user_keeping_the_pizzasys_id(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        $user = User::find(7);

        $this->assertNotNull($user);
        $this->assertSame(7, $user->id);
        $this->assertSame('dana@example.com', $user->email);
        $this->assertTrue($user->is_active);
    }

    public function test_the_users_table_stores_no_password(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        // Auth is entirely remote; a credential we never verify is a liability.
        $this->assertArrayNotHasKey('password', User::find(7)->getAttributes());
    }

    public function test_it_applies_an_updated_delta(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        app(UserUpdatedHandler::class)->handle([
            'data' => [
                'user_id' => 7,
                'changed_fields' => [
                    'email' => ['from' => 'dana@example.com', 'to' => 'dana.ruiz@example.com'],
                ],
            ],
        ]);

        $user = User::find(7);
        $this->assertSame('dana.ruiz@example.com', $user->email);
        $this->assertSame('Dana Ruiz', $user->name); // not in the delta
    }

    public function test_an_update_before_its_create_throws_so_the_consumer_retries(): void
    {
        $this->expectExceptionMessage('user 7 not synced yet');

        app(UserUpdatedHandler::class)->handle([
            'data' => [
                'user_id' => 7,
                'changed_fields' => ['name' => ['from' => 'a', 'to' => 'b']],
            ],
        ]);
    }

    public function test_delete_deactivates_rather_than_removing_the_row(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        app(UserDeletedHandler::class)->handle(['data' => ['user_id' => 7]]);

        $user = User::find(7);
        // The row survives so scheduling rows keep their authorship.
        $this->assertNotNull($user);
        $this->assertFalse($user->is_active);
    }

    public function test_a_recreated_user_is_reactivated(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());
        app(UserDeletedHandler::class)->handle(['data' => ['user_id' => 7]]);

        app(UserCreatedHandler::class)->handle($this->createdEvent());

        $this->assertTrue(User::find(7)->is_active);
        $this->assertSame(1, User::count());
    }
}
