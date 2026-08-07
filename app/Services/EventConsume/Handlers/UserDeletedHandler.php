<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $this->asInt(
            data_get($event, 'data.user_id')
                ?? data_get($event, 'user_id')
                ?? data_get($event, 'data.user.id')
                ?? data_get($event, 'user.id')
        );

        if ($userId <= 0) {
            throw new \Exception('UserDeletedHandler: missing/invalid user_id');
        }

        DB::transaction(function () use ($userId) {
            // Deactivate rather than delete. Scheduling rows carry authorship
            // (shifts.created_by_user_id, published_schedules.published_by_user_id),
            // and removing the row would either break those references or erase
            // who did what. Authentication is unaffected either way — pizzasys
            // stops issuing tokens for a deleted user, and this service never
            // authenticates locally.
            User::query()->whereKey($userId)->update(['is_active' => false]);
        });
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
