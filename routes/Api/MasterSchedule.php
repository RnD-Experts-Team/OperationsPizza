<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MasterScheduleController;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('stores/{store}')->group(function () {

        Route::get('master/published', [MasterScheduleController::class, 'getPublishedSchedules']); 

        Route::get('master-schedules/trashed', [MasterScheduleController::class, 'trashed']);

        Route::get('master-schedules', [MasterScheduleController::class, 'index']);//only master schedules 

        Route::post('master-schedules/create', [MasterScheduleController::class, 'store']);//store with schedules
        Route::get('master-schedules/{id}', [MasterScheduleController::class, 'show']);//master schedule with schedules
        Route::put('master-schedules/update/{id}', [MasterScheduleController::class, 'update']);//update master schedule with schedules
        
        Route::delete('master-schedules/{id}', [MasterScheduleController::class, 'softDelete']);//soft delete master schedule
        Route::post('master-schedules/{id}/restore', [MasterScheduleController::class, 'restore']);//restore master schedule
        Route::delete('master-schedules/{id}/force', [MasterScheduleController::class, 'forceDelete']);//force delete master schedule
        Route::delete('schedules/delete/{id}', [MasterScheduleController::class, 'deleteSchedule']);
        Route::post('schedules/delete/{id}/restore', [MasterScheduleController::class, 'restoreSchedule']);
        Route::delete('schedules/delete/{id}/force', [MasterScheduleController::class, 'forceDeleteSchedule']);

        Route::post('master-schedules/{id}/publish', [MasterScheduleController::class, 'publish']);//publish master schedule
        Route::post('master-schedules/{id}/unpublish', [MasterScheduleController::class, 'unpublish']);//unpublish master schedule

    
        Route::get('scheduling/init', [MasterScheduleController::class, 'initScheduling']);//initialize scheduling for a date range and store


        Route::post('master-schedules/copy', [MasterScheduleController::class, 'copyWeek']);//copy week's schedule
    });
}); 