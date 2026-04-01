<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SkillController;
Route::middleware('auth:sanctum')->group(function () {

    Route::apiResource('skills', SkillController::class);
});