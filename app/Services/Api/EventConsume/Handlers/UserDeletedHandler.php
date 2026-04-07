<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(
            data_get($event, 'data.user_id')
            ?? data_get($event, 'user_id')
        );

        if ($id <= 0) {
            throw new \Exception('UserDeletedHandler: invalid user id');
        }

        DB::transaction(function () use ($id) {
            User::query()->where('id', $id)->delete();
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