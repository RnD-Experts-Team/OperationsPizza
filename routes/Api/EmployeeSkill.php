<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EmployeeSkillController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('stores/{store}/employee-skills', [EmployeeSkillController::class, 'index']);
    Route::post('stores/{store}/employee-skills', [EmployeeSkillController::class, 'store']);
    Route::put('stores/{store}/employee-skills/{employee_skill}', [EmployeeSkillController::class, 'update']);
    Route::delete('stores/{store}/employee-skills/{employee_skill}', [EmployeeSkillController::class, 'destroy']);
});