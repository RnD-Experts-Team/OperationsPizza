<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EmployeeAvailabilityController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('stores/{store}/employee-availabilities', [EmployeeAvailabilityController::class, 'index']);
    Route::post('stores/{store}/employee-availabilities', [EmployeeAvailabilityController::class, 'store']);
     Route::put('stores/{store}/employee-availabilities/{employee_availability}', [EmployeeAvailabilityController::class, 'update']);
    Route::delete('stores/{store}/employee-availabilities/{employee_availability}', [EmployeeAvailabilityController::class, 'destroy']);
});