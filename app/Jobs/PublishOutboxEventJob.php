<?php

namespace App\Jobs;

use App\Models\OperationsOutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    /**
     * Retry delays in seconds. HiringPizza's copy has $tries = 10 with no
     * backoff, i.e. ten immediate retries against NATS — don't inherit that.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30, 60, 120, 300];

    public function __construct(public string $outboxEventId)
    {
    }

    public function handle(JetStreamPublisher $publisher): void
    {
        $event = OperationsOutboxEvent::find($this->outboxEventId);

        if (!$event)
            return;

        if ($event->published_at)
            return;

        try {
            $publisher->publish($event->subject, $event->payload);

            $event->update([
                'published_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $event->increment('attempts');

            $event->update([
                'last_error' => $e->getMessage(),
            ]);

            throw $e; // let Laravel retry
        }
    }

    public function failed(\Throwable $e): void
    {
        OperationsOutboxEvent::query()
            ->where('id', $this->outboxEventId)
            ->update([
                'last_error' => $e->getMessage(),
            ]);
    }
}
