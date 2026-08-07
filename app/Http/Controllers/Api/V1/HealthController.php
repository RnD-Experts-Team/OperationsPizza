<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HealthController extends Controller
{
    /**
     * Reaching this at all proves: the bearer token verified against pizzasys,
     * ext.authorized was true, and the user exists in our replicated table.
     * The counts prove the NATS consumer has actually run.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'service' => 'operations-system',
                'time' => now()->utc()->toIso8601String(),
                'dev_mode' => (bool) config('nats.dev_mode'),
                'subject_type' => $request->attributes->get('authz_subject_type'),
                'user' => [
                    'id' => Auth::id(),
                    'name' => Auth::user()?->name,
                ],
                'roles' => $request->attributes->get('authz_roles', []),
                'replication' => [
                    'users' => \App\Models\User::query()->count(),
                    'stores' => Store::query()->count(),
                ],
            ],
        ]);
    }
}
