<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Replicated from pizzasys via auth.v1.user.* — this service never creates a
 * user. Still an Authenticatable because AuthTokenStoreScopeMiddleware calls
 * Auth::login() after pizzasys has already verified the token; there is no
 * password here and nothing local ever checks credentials.
 */
#[Fillable(['id', 'name', 'email'])]
class User extends Authenticatable
{
    use ReplicatedModel;

    /**
     * Nothing to cast: this table holds id, name and email only. Verification
     * state, roles and active status all live in pizzasys, which is consulted
     * on every request rather than mirrored here.
     */
    protected function casts(): array
    {
        return [];
    }
}
