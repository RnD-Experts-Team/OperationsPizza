<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SkillController;
Route::middleware('auth:sanctum')->group(function () {

    Route::apiResource('skills', SkillController::class);

    Route::get('skills/trashed/all', [SkillController::class, 'trashed']);
    Route::post('skills/{id}/restore', [SkillController::class, 'restore']);
});


