<?php

use Illuminate\Support\Facades\Route; 
use App\Http\Controllers\Api\EmployeeAvailabilityController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('employee-availabilities', EmployeeAvailabilityController::class);
});