<?php

namespace App\Services\OperationsEvents;

use App\Models\OperationsOutboxEvent;

class OperationsOutboxService
{
    public function record(string $subject, array $payload): OperationsOutboxEvent
    {
        $subject = $this->applyEnvironmentPrefix($subject);

        return OperationsOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
    }

    private function applyEnvironmentPrefix(string $subject): string
    {
        if (!config('nats.dev_mode')) {
            return $subject;
        }

        // Only transform operations + notifications domains
        if (str_starts_with($subject, 'operations.v1.')) {
            return str_replace('operations.v1.', 'operations.testing.v1.', $subject);
        }

        if (str_starts_with($subject, 'notifications.v1.')) {
            return str_replace('notifications.v1.', 'notifications.testing.v1.', $subject);
        }

        return $subject;
    }
}
