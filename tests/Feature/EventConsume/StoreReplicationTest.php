<?php

namespace Tests\Feature\EventConsume;

use App\Models\Store;
use App\Services\EventConsume\Handlers\StoreCreatedHandler;
use App\Services\EventConsume\Handlers\StoreDeletedHandler;
use App\Services\EventConsume\Handlers\StoreUpdatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreReplicationTest extends TestCase
{
    use RefreshDatabase;

    /** The real auth.v1.store.created payload from pizzasys StoreManagementService. */
    private function createdEvent(array $overrides = []): array
    {
        return [
            'data' => [
                'store' => array_merge([
                    'id' => 42,
                    'store_id' => '03759-00001',
                    'name' => 'Downtown',
                    'metadata' => ['timezone' => 'America/New_York'],
                    'is_active' => true,
                    'created_at' => '2026-08-01T00:00:00+00:00',
                    'updated_at' => '2026-08-01T00:00:00+00:00',
                ], $overrides),
            ],
        ];
    }

    public function test_it_replicates_a_store_with_the_external_store_number_as_the_key(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        $store = Store::find(42);

        $this->assertNotNull($store);
        // store_number must be pizzasys' external string, NOT the display name —
        // it is the {storeId} segment in our API routes.
        $this->assertSame('03759-00001', $store->store_number);
        $this->assertSame('Downtown', $store->name);
        $this->assertSame('America/New_York', $store->timezone);
        $this->assertTrue($store->is_active);
    }

    public function test_it_keeps_the_pizzasys_id_instead_of_auto_incrementing(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent(['id' => 907]));

        $this->assertSame(907, Store::sole()->id);
    }

    public function test_it_falls_back_to_the_default_timezone_when_metadata_has_none(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent(['metadata' => null]));

        $this->assertSame(
            config('operations.default_timezone'),
            Store::find(42)->timezone
        );
    }

    public function test_it_ignores_an_invalid_timezone_in_metadata(): void
    {
        app(StoreCreatedHandler::class)->handle(
            $this->createdEvent(['metadata' => ['timezone' => 'Mars/Olympus_Mons']])
        );

        $this->assertSame(
            config('operations.default_timezone'),
            Store::find(42)->timezone
        );
    }

    public function test_a_redelivered_created_event_is_idempotent(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        $this->assertSame(1, Store::count());
    }

    public function test_it_applies_an_updated_delta(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        app(StoreUpdatedHandler::class)->handle([
            'data' => [
                'store_id' => 42, // NOTE: the integer pk on `updated`, not the string
                'changed_fields' => [
                    'name' => ['from' => 'Downtown', 'to' => 'Downtown East'],
                    'is_active' => ['from' => true, 'to' => false],
                ],
            ],
        ]);

        $store = Store::find(42);
        $this->assertSame('Downtown East', $store->name);
        $this->assertFalse($store->is_active);
        // Not in the delta → untouched.
        $this->assertSame('America/New_York', $store->timezone);
    }

    public function test_an_update_without_a_timezone_in_metadata_does_not_reset_it(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        app(StoreUpdatedHandler::class)->handle([
            'data' => [
                'store_id' => 42,
                'changed_fields' => [
                    'metadata' => ['from' => null, 'to' => ['region' => 'east']],
                ],
            ],
        ]);

        // The whole point: a metadata change that says nothing about timezone
        // must never overwrite a good value with the app default.
        $this->assertSame('America/New_York', Store::find(42)->timezone);
    }

    public function test_an_update_before_its_create_throws_so_the_consumer_retries(): void
    {
        $this->expectExceptionMessage('store 42 not synced yet');

        app(StoreUpdatedHandler::class)->handle([
            'data' => [
                'store_id' => 42,
                'changed_fields' => ['name' => ['from' => 'a', 'to' => 'b']],
            ],
        ]);
    }

    public function test_delete_soft_deletes_and_a_later_create_restores(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        app(StoreDeletedHandler::class)->handle(['data' => ['store_id' => 42]]);
        $this->assertNull(Store::find(42));
        $this->assertNotNull(Store::withTrashed()->find(42));

        app(StoreCreatedHandler::class)->handle($this->createdEvent());
        $this->assertNotNull(Store::find(42));
    }
}
