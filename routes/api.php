<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\AuthController;

// POST Request , /register URL ที่เราเปิดไว้ให้ฝั่ง Frontend เรียกใช้งาน และเมื่อมีการเรียกใช้งาน URL นี้ จะให้ไปทำงานที่ฟังก์ชัน register ใน AuthController
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);  

// OTP
Route::post('/forgot-password', [AuthController::class, 'sendOtp']);
// verify OTP
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
// reset password
Route::post('/reset-password', [AuthController::class, 'resetPassword']);


// Public Route (ไม่ต้องใช้ Token)
Route::get('/profiles/{username}', [ProfileController::class, 'showPublic']);

Route::put('/profiles/{username}/test-update', [ProfileController::class, 'updateForTest']);

// Protected Routes (ต้องส่ง Bearer Token)
// สมมติว่าใช้ Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [ProfileController::class, 'showMyProfile']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
});
