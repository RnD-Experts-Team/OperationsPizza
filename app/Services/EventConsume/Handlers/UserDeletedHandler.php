<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\Log;

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

        // Deliberately keep the row and change nothing.
        //
        // Scheduling rows carry authorship (shifts.created_by_user_id,
        // published_schedules.published_by_user_id); deleting the user would
        // erase who built a schedule, and we hold no flag to mark them inactive
        // because pizzasys owns that decision. Access is unaffected either way:
        // pizzasys stops issuing tokens for a deleted user, and every request
        // here is verified against it, so a lingering row grants nothing.
        Log::info('pizzasys user deleted; keeping the local row for authorship', [
            'user_id' => $userId,
            'exists_locally' => User::query()->whereKey($userId)->exists(),
        ]);
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
