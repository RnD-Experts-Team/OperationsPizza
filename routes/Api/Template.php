<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ScheduleTemplateController;
Route::middleware('auth:sanctum')->group(function () {
    Route::get('schedule-templates/all', [ScheduleTemplateController::class, 'allTemplate']);
    // general
    Route::post('schedule-templates/save', [ScheduleTemplateController::class, 'saveTemplate']);

    // from master
    Route::post('schedule-templates/save/{id}', [ScheduleTemplateController::class, 'saveTemplate']);
     Route::post('schedule-templates/load', [ScheduleTemplateController::class, 'loadTemplate']);
    Route::get('schedule-templates/show/{id}', [ScheduleTemplateController::class, 'showTemplate']);
    Route::delete('schedule-templates/delete/{id}', [ScheduleTemplateController::class, 'DeleteTemplate']);
});