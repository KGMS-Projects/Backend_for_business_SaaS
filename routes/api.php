<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Handles authentication, service requests, comments, and notifications.
| Protected routes are guarded by Laravel Sanctum and Policies.
*/

// Public Authentication
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.login');

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markAllRead']);

    // Service Requests List
    Route::get('requests', [ServiceRequestController::class, 'index'])->can('viewAny', ServiceRequest::class);

    // Employee Actions
    Route::post('requests', [ServiceRequestController::class, 'store'])->can('create', ServiceRequest::class);
    Route::put('requests/{serviceRequest}', [ServiceRequestController::class, 'update'])->can('update', 'serviceRequest');
    Route::post('requests/{serviceRequest}/submit', [ServiceRequestController::class, 'submit'])->can('submit', 'serviceRequest');

    // Manager Actions
    Route::post('requests/{serviceRequest}/decision', [ServiceRequestController::class, 'decision'])->can('decide', 'serviceRequest');

    // Operations Actions
    Route::post('requests/{serviceRequest}/assign', [ServiceRequestController::class, 'assign'])->can('assign', 'serviceRequest');
    Route::post('requests/{serviceRequest}/status', [ServiceRequestController::class, 'status'])->can('changeStatus', 'serviceRequest');

    // Shared Details, Audit Trail, & Comments
    Route::get('requests/{serviceRequest}', [ServiceRequestController::class, 'show'])->can('view', 'serviceRequest');
    Route::get('requests/{serviceRequest}/activities', [ActivityController::class, 'index'])->can('view', 'serviceRequest');
    Route::post('requests/{serviceRequest}/comments', [CommentController::class, 'store'])->can('comment', 'serviceRequest');
});
