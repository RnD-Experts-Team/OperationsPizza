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
#[Fillable(['id', 'name', 'email', 'email_verified_at', 'is_active'])]
class User extends Authenticatable
{
    use ReplicatedModel;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
