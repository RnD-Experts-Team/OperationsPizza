<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $user = data_get($event, 'data.user');

        if (!is_array($user)) {
            throw new \Exception('UserCreatedHandler: missing user payload');
        }

        $id = $this->asInt(data_get($user, 'id'));
        $name = data_get($user, 'name');
        $email = data_get($user, 'email');

        if ($id <= 0) {
            throw new \Exception('UserCreatedHandler: invalid user id');
        }

        if (!$email) {
            throw new \Exception('UserCreatedHandler: missing email');
        }

        DB::transaction(function () use ($id, $name, $email) {
            User::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name'  => (string) $name,
                    'email' => (string) $email,
                ]
            );
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