<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;      // ⭐️ นำเข้า File (สำหรับอ่านรูปภาพ)
use Illuminate\Support\Facades\Response;  // ⭐️ นำเข้า Response (สำหรับส่ง header CORS)
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\ExportController;

// Public Routes (ไม่ต้องใช้ Token)

// ⭐️ เพิ่ม API เส้นพิเศษสำหรับปลดล็อก CORS ให้รูปโปรไฟล์ ⭐️
Route::get('/get-avatar/{filename}', function ($filename) {
    // กำหนดพาทที่เก็บรูปภาพ (อ้างอิงตาม storage ของ Laravel)
    $path = storage_path('app/public/avatars/' . $filename);

    if (!File::exists($path)) {
        abort(404);
    }

    $file = File::get($path);
    $type = File::mimeType($path);

    return Response::make($file, 200)
        ->header('Content-Type', $type)
        ->header('Access-Control-Allow-Origin', '*'); // 👈 กุญแจสำคัญอนุญาตให้ React ดึงรูปได้
});

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);  
Route::post('/auth/google', [AuthController::class, 'googleLogin']);

// OTP
Route::post('/forgot-password', [AuthController::class, 'sendOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Profiles & Analytics
Route::get('/profiles/{username}', [ProfileController::class, 'showPublic']);
Route::put('/profiles/{username}/test-update', [ProfileController::class, 'updateForTest']);
Route::post('/analytics/track/{username}', [AnalyticsController::class, 'track']);

// เส้นทางสำหรับให้หน้าบ้าน (React) กดขอส่งอีเมลใหม่อีกครั้ง
Route::post('/email/verification-notification', [AuthController::class, 'resendVerification']);

// เส้นทางสำหรับรองรับการคลิกลิงก์ยาวๆ จากในอีเมล
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
     ->name('verification.verify');

Route::post('/admin/export-report', [ExportController::class, 'exportReport']);

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

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Admin
    Route::get('/admin', [AdminController::class, 'getUsers']);
    Route::put('/admin/{id}/role', [AdminController::class, 'updateRole']);
    Route::put('/admin/{id}/status', [AdminController::class, 'toggleStatus']);
    Route::delete('/admin/{id}', [AdminController::class, 'deleteUser']);

    Route::get('/admin/dashboard-stats', [AdminDashboardController::class, 'getDashboardStats']);

    Route::post('/admin/remind-single/{id}', [AdminDashboardController::class, 'sendReminderSingle']);
    Route::post('/admin/remind-bulk', [AdminDashboardController::class, 'sendReminderBulk']);
});