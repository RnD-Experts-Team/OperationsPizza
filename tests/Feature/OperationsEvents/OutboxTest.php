<?php

namespace Tests\Feature\OperationsEvents;

use App\Models\OperationsOutboxEvent;
use App\Services\OperationsEvents\OperationsEventFactory;
use App\Services\OperationsEvents\OperationsOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_factory_builds_a_cloudevents_envelope_sourced_to_this_service(): void
    {
        $envelope = app(OperationsEventFactory::class)
            ->make('operations.v1.shift.created', ['shift_id' => 1]);

        $this->assertSame('1.0', $envelope['specversion']);
        $this->assertSame('operations-system', $envelope['source']);
        $this->assertSame('operations.v1.shift.created', $envelope['type']);
        // JetStreamConsumer routes on `subject`, so it must mirror `type`.
        $this->assertSame($envelope['type'], $envelope['subject']);
        $this->assertNotEmpty($envelope['id']);
        $this->assertSame(['shift_id' => 1], $envelope['data']);
        $this->assertArrayHasKey('correlation_id', $envelope['meta']);
    }

    public function test_dev_mode_rewrites_subjects_onto_the_testing_streams(): void
    {
        config(['nats.dev_mode' => true]);

        $envelope = app(OperationsEventFactory::class)
            ->make('operations.v1.shift.created', []);

        $this->assertSame('operations.testing.v1.shift.created', $envelope['type']);
        $this->assertSame('operations.testing.v1.shift.created', $envelope['subject']);
    }

    public function test_recording_stores_an_unpublished_row_for_the_publisher_to_pick_up(): void
    {
        $envelope = app(OperationsEventFactory::class)
            ->make('operations.v1.shift.created', ['shift_id' => 1]);

        $row = app(OperationsOutboxService::class)
            ->record('operations.v1.shift.created', $envelope);

        $this->assertSame('operations.v1.shift.created', $row->subject);
        $this->assertSame($row->subject, $row->type);
        $this->assertSame($envelope, $row->payload);

        // attempts/published_at come from DB defaults, so read them back.
        $row->refresh();
        $this->assertNull($row->published_at);
        $this->assertSame(0, $row->attempts);
        $this->assertSame(1, OperationsOutboxEvent::whereNull('published_at')->count());
    }

    public function test_the_publisher_resolves_operations_subjects_to_the_operations_stream(): void
    {
        // Guards against a subject that no configured publisher matches, which
        // JetStreamPublisher only discovers at publish time — i.e. after the
        // event has already been committed to the outbox.
        $publisher = new \App\Services\Nats\JetStreamPublisher();

        $resolve = (new \ReflectionClass($publisher))->getMethod('resolvePublishTarget');
        $resolve->setAccessible(true);

        $this->assertSame(
            'OPERATIONS_EVENTS',
            $resolve->invoke($publisher, 'operations.v1.shift.created')['name']
        );
        $this->assertSame(
            'NOTIFICATIONS_EVENTS',
            $resolve->invoke($publisher, 'notifications.v1.notification.role.send')['name']
        );
    }

    public function test_the_publisher_rejects_a_subject_with_no_configured_stream(): void
    {
        $publisher = new \App\Services\Nats\JetStreamPublisher();

        $resolve = (new \ReflectionClass($publisher))->getMethod('resolvePublishTarget');
        $resolve->setAccessible(true);

        $this->expectExceptionMessage("No publish target configured for subject 'hiring.v1.employee.created'");

        // We consume hiring events; publishing one would be a bug.
        $resolve->invoke($publisher, 'hiring.v1.employee.created');
    }
}
