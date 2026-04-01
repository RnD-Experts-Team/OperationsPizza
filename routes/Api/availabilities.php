<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityController;
Route::apiResource('availabilities', AvailabilityController::class);