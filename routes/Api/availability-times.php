<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityTimeController;
Route::apiResource('availability-times', AvailabilityTimeController::class);