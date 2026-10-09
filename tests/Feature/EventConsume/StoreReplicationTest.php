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
    }

    public function test_it_keeps_the_pizzasys_id_instead_of_auto_incrementing(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent(['id' => 907]));

        $this->assertSame(907, Store::sole()->id);
    }

    public function test_it_ignores_the_fields_we_deliberately_do_not_store(): void
    {
        // pizzasys sends is_active and a metadata timezone; we store neither.
        // is_active lives in pizzasys (consulted on every request), and the
        // scheduling timezone comes from the mapped Humanity location, because
        // that is what Humanity interprets our shift times in.
        app(StoreCreatedHandler::class)->handle($this->createdEvent([
            'is_active' => false,
            'metadata' => ['timezone' => 'America/New_York'],
        ]));

        $store = Store::find(42);

        $this->assertNotNull($store);
        $this->assertSame(['id', 'store_number', 'name', 'created_at', 'updated_at', 'deleted_at'],
            array_keys($store->getAttributes()));
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

        // Only `name` is applied; is_active in the delta is ignored.
        $this->assertSame('Downtown East', Store::find(42)->name);
    }

    public function test_an_update_carrying_only_ignored_fields_is_a_no_op(): void
    {
        app(StoreCreatedHandler::class)->handle($this->createdEvent());

        app(StoreUpdatedHandler::class)->handle([
            'data' => [
                'store_id' => 42,
                'changed_fields' => [
                    'metadata' => ['from' => null, 'to' => ['timezone' => 'America/New_York']],
                    'is_active' => ['from' => true, 'to' => false],
                ],
            ],
        ]);

        // Nothing we store changed, and nothing threw.
        $this->assertSame('Downtown', Store::find(42)->name);
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
