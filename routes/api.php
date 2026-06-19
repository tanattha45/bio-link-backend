<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\AnalyticsController;


// Public Routes (ไม่ต้องใช้ Token)

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);  

// OTP
Route::post('/forgot-password', [AuthController::class, 'sendOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Profiles & Analytics
Route::get('/profiles/{username}', [ProfileController::class, 'showPublic']);
Route::put('/profiles/{username}/test-update', [ProfileController::class, 'updateForTest']);
Route::post('/analytics/track/{username}', [AnalyticsController::class, 'track']);



//  Protected Routes (ต้องส่ง Bearer Token)
Route::middleware('auth:sanctum')->group(function () {
    
    // --- Blocks ---
    Route::get('/blocks', [BlockController::class, 'index']); 
    Route::post('/blocks', [BlockController::class, 'store']);
    Route::get('/blocks/{id}', [BlockController::class, 'show']);
    Route::put('/blocks/{id}', [BlockController::class, 'update']);
    Route::delete('/blocks/{id}', [BlockController::class, 'destroy']);
    
    // --- User Profile ---
    Route::get('/user/profile', [ProfileController::class, 'showMyProfile']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
    
    // --- Analytics ---
    Route::get('/user/analytics', [AnalyticsController::class, 'getDashboardStats']);

});