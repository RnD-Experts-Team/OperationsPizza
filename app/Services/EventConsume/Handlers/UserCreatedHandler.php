<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userPayload = $this->extractUserPayload($event);

        $id = $this->asInt(data_get($userPayload, 'id'));
        if ($id <= 0) {
            throw new \Exception('UserCreatedHandler: missing/invalid user.id');
        }

        $email = (string) data_get($userPayload, 'email', '');
        if ($email === '') {
            throw new \Exception('UserCreatedHandler: missing user.email');
        }

        $name = (string) data_get($userPayload, 'name', 'Unknown');

        DB::transaction(function () use ($id, $name, $email) {
            // Identity only. The event also carries email_verified_at, roles and
            // an active flag; none are stored, because pizzasys owns the whole
            // authentication and authorization decision and this service asks it
            // on every request (AuthTokenStoreScopeMiddleware). A local copy
            // could only ever go stale and disagree.
            //
            // The row exists purely so shifts.created_by_user_id and
            // published_schedules.published_by_user_id can name a human.
            User::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name'  => $name,
                    'email' => $email,
                ]
            );
        });
    }

    private function extractUserPayload(array $event): array
    {
        $user = data_get($event, 'data.user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'payload.user');
        if (is_array($user)) return $user;

        throw new \Exception('UserCreatedHandler: user payload not found in event');
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
