<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RequestActionController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefix /api, rate limited by the "api" limiter)
|--------------------------------------------------------------------------
| Each `->can(ability, ...)` runs ServiceRequestPolicy BEFORE the request body is validated, so
| an unauthorised caller always gets a 403 and can never probe validation rules with a bad payload.
| Reading top to bottom, this file is also the permission matrix of the whole API.
*/

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // In-app notifications written by the queued notification job.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markAllRead']);

    // Everyone gets a list scoped to what their role may see.
    Route::get('requests', [ServiceRequestController::class, 'index'])->can('viewAny', ServiceRequest::class);

    // Employee
    Route::post('requests', [ServiceRequestController::class, 'store'])->can('create', ServiceRequest::class);
    Route::put('requests/{serviceRequest}', [ServiceRequestController::class, 'update'])->can('update', 'serviceRequest');
    Route::post('requests/{serviceRequest}/submit', [RequestActionController::class, 'submit'])->can('submit', 'serviceRequest');

    // Manager
    Route::post('requests/{serviceRequest}/decision', [RequestActionController::class, 'decision'])->can('decide', 'serviceRequest');

    // Operations
    Route::post('requests/{serviceRequest}/assign', [RequestActionController::class, 'assign'])->can('assign', 'serviceRequest');
    Route::post('requests/{serviceRequest}/status', [RequestActionController::class, 'status'])->can('changeStatus', 'serviceRequest');

    // Anyone who can see the request
    Route::get('requests/{serviceRequest}', [ServiceRequestController::class, 'show'])->can('view', 'serviceRequest');
    Route::get('requests/{serviceRequest}/activities', [ActivityController::class, 'index'])->can('view', 'serviceRequest');
    Route::post('requests/{serviceRequest}/comments', [CommentController::class, 'store'])->can('comment', 'serviceRequest');
});
