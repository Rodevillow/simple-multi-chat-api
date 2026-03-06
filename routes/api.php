<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:30,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:120,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:10,1');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:20,1');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('settings')->middleware('auth:api')->group(function (): void {
    Route::get('/profile', [ProfileSettingsController::class, 'show']);
    Route::put('/profile', [ProfileSettingsController::class, 'update'])->middleware('throttle:60,1');
    Route::post('/profile/avatar-upload-url', [ProfileSettingsController::class, 'avatarUploadUrl'])->middleware('throttle:30,1');
    Route::post('/profile/avatar-upload', [ProfileSettingsController::class, 'avatarUpload'])->middleware('throttle:30,1');
});

Route::prefix('chats')->middleware('auth:api')->group(function (): void {
    Route::get('/', [ChatController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/users', [ChatController::class, 'users'])->middleware('throttle:120,1');
    Route::get('/users/search', [ChatController::class, 'searchUsers'])->middleware('throttle:120,1');
    Route::post('/contacts', [ChatController::class, 'addContact'])->middleware('throttle:60,1');
    Route::post('/direct', [ChatController::class, 'createDirect'])->middleware('throttle:60,1');
    Route::post('/group', [ChatController::class, 'createGroup'])->middleware('throttle:60,1');
    Route::get('/{chat}/messages', [ChatController::class, 'messages'])->middleware('throttle:240,1');
    Route::post('/{chat}/messages', [ChatController::class, 'sendMessage'])->middleware('throttle:240,1');
    Route::post('/{chat}/read', [ChatController::class, 'markRead'])->middleware('throttle:240,1');
});
