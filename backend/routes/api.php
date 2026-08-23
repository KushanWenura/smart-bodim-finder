<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OwnerListingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProximityController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['service' => 'smart-bodim-api', 'status' => 'healthy', 'version' => '1.0.0']);
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:login');
        Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:login');
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });
    Route::get('/meta', [ListingController::class, 'meta']);
    Route::get('/listings', [ListingController::class, 'index']);
    Route::get('/listings/featured', [ListingController::class, 'featured']);
    Route::get('/listings/{listing}', [ListingController::class, 'show']);
    Route::get('/search', SearchController::class)->middleware('throttle:search');
    Route::post('/assistant/chat', [SearchController::class, 'assistant'])->middleware('throttle:search');
    Route::get('/destinations', [ProximityController::class, 'destinations']);
    Route::get('/proximity', [ProximityController::class, 'search'])->middleware('throttle:search');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:login');
        Route::delete('/account', [AccountController::class, 'archive']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('throttle:message');
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::post('/conversations/{conversation}/archive', [ConversationController::class, 'archive']);

        Route::middleware('role:tenant')->group(function () {
            Route::get('/favorites', [FavoriteController::class, 'index']);
            Route::get('/recommendations', [SearchController::class, 'recommendations']);
            Route::put('/favorites/{listing}', [FavoriteController::class, 'store']);
            Route::delete('/favorites/{listing}', [FavoriteController::class, 'destroy']);
            Route::post('/listings/{listing}/report', [ListingController::class, 'report']);
            Route::post('/reviews', [ReviewController::class, 'store'])->middleware('throttle:review');
            Route::get('/my-reviews', [ReviewController::class, 'mine']);
            Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
            Route::post('/reviews/{review}/report', [ReviewController::class, 'report']);
            Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:message');
            Route::apiResource('/saved-searches', SavedSearchController::class)->only(['index', 'store', 'destroy']);
        });
        Route::prefix('owner')->middleware('role:owner')->group(function () {
            Route::get('/listings', [OwnerListingController::class, 'index']);
            Route::get('/reviews', [ReviewController::class, 'ownerReviews']);
            Route::get('/listings/{listing}/history', [OwnerListingController::class, 'history']);
            Route::post('/listings', [OwnerListingController::class, 'store']);
            Route::put('/listings/{listing}', [OwnerListingController::class, 'update']);
            Route::post('/listings/{listing}/submit', [OwnerListingController::class, 'submit']);
            Route::post('/listings/{listing}/deactivate', [OwnerListingController::class, 'deactivate']);
            Route::post('/listings/{listing}/images', [OwnerListingController::class, 'upload'])->middleware('throttle:upload');
            Route::delete('/listings/{listing}/images/{image}', [OwnerListingController::class, 'removeImage']);
        });
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/search', [AdminController::class, 'search']);
            Route::get('/listings', [AdminController::class, 'listings']);
            Route::post('/listings/{listing}/{action}', [AdminController::class, 'moderate'])->whereIn('action', ['approve', 'reject', 'suspend', 'restore']);
            Route::get('/owners', [AdminController::class, 'owners']);
            Route::post('/owners/{ownerProfile}/verify', [AdminController::class, 'verifyOwner']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::post('/users/{user}/status', [AdminController::class, 'userStatus']);
            Route::get('/reviews', [AdminController::class, 'reviews']);
            Route::post('/reviews/{review}/{action}', [AdminController::class, 'moderateReview'])->whereIn('action', ['hide', 'restore']);
            Route::post('/notifications', [AdminController::class, 'notify']);
            Route::get('/audit-logs', [AdminController::class, 'audit']);
        });
    });
});
