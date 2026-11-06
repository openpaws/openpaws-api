<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', LoginController::class)->middleware('throttle:login');
    Route::post('logout', LogoutController::class)->middleware('auth:sanctum');
    Route::post('email/verify', [EmailVerificationController::class, 'verify']);
    Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('auth:sanctum');
    Route::post('password/forgot', [PasswordController::class, 'forgot']);
    Route::post('password/reset', [PasswordController::class, 'reset']);
});

Route::middleware('auth:sanctum')->prefix('me')->group(function () {
    Route::get('/', [MeController::class, 'show']);
    Route::patch('/', [MeController::class, 'update']);
    Route::patch('password', [MeController::class, 'password']);
    Route::patch('settings', [MeController::class, 'settings']);
    Route::post('adult-confirmation', [MeController::class, 'adultConfirmation']);
    Route::patch('profile', [ProfileController::class, 'update']);
});

Route::get('users/{username}', [UserController::class, 'show']);
