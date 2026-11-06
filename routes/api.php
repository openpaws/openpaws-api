<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\BrowseController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\UploadController;
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
    Route::put('avatar', [MeController::class, 'avatar']);
    Route::put('banner', [MeController::class, 'banner']);
    Route::patch('profile', [ProfileController::class, 'update']);
});

Route::get('users/{username}', [UserController::class, 'show']);
Route::get('users/{username}/folders', [UserController::class, 'folders']);
Route::get('users/{username}/submissions', [UserController::class, 'submissions']);

Route::middleware('auth:sanctum')->prefix('folders')->group(function () {
    Route::post('/', [FolderController::class, 'store']);
    Route::patch('{publicId}', [FolderController::class, 'update']);
    Route::delete('{publicId}', [FolderController::class, 'destroy']);
    Route::put('{publicId}/order', [FolderController::class, 'order']);
});

Route::middleware('auth:sanctum')->prefix('uploads')->group(function () {
    Route::post('/', [UploadController::class, 'store'])->middleware('throttle:uploads');
    Route::post('{id}/parts', [UploadController::class, 'parts']);
    Route::post('{id}/complete', [UploadController::class, 'complete']);
});

Route::get('submissions/{submission}', [SubmissionController::class, 'show']);
Route::middleware('auth:sanctum')->prefix('submissions')->group(function () {
    Route::post('/', [SubmissionController::class, 'store']);
    Route::patch('{submission}', [SubmissionController::class, 'update']);
    Route::delete('{submission}', [SubmissionController::class, 'destroy']);
    Route::post('{submission}/retry', [SubmissionController::class, 'retry']);
});

Route::get('tags/autocomplete', [TagController::class, 'autocomplete']);
Route::get('tags/{slug}', [TagController::class, 'show']);

Route::get('browse', BrowseController::class);
Route::get('search', SearchController::class)->middleware('throttle:search');
