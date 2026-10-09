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
    }

    public function test_it_stores_identity_only(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        // Roles, verification state and active status all live in pizzasys,
        // which is consulted on every request — a local copy could only drift.
        $columns = array_keys(User::find(7)->getAttributes());
        sort($columns);

        $this->assertSame(['created_at', 'email', 'id', 'name', 'updated_at'], $columns);
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

    public function test_delete_keeps_the_row_so_authorship_survives(): void
    {
        app(UserCreatedHandler::class)->handle($this->createdEvent());

        app(UserDeletedHandler::class)->handle(['data' => ['user_id' => 7]]);

        // Deleting would erase who built a schedule
        // (shifts.created_by_user_id). Access is unaffected: pizzasys stops
        // issuing tokens, and every request here is verified against it.
        $this->assertNotNull(User::find(7));
    }

    public function test_delete_still_validates_its_payload(): void
    {
        $this->expectExceptionMessage('missing/invalid user_id');

        app(UserDeletedHandler::class)->handle(['data' => []]);
    }
}
