<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(
            data_get($event, 'data.user_id')
            ?? data_get($event, 'user_id')
        );

        if ($id <= 0) {
            throw new \Exception('UserUpdatedHandler: invalid user id');
        }

        $changed = data_get($event, 'data.changed_fields', []);

        $name  = data_get($changed, 'name.to');
        $email = data_get($changed, 'email.to');

        if ($name === null && $email === null) {
            return; // nothing to update
        }

        DB::transaction(function () use ($id, $name, $email) {
            $user = User::query()->find($id);

            if (!$user) {
                // do not create here (event order matters)
                return;
            }

            $update = [];

            if ($name !== null) {
                $update['name'] = (string) $name;
            }

            if ($email !== null) {
                $update['email'] = (string) $email;
            }

            if (!empty($update)) {
                $user->update($update);
            }
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