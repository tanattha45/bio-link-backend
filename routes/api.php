<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileController;

// Public Route (ไม่ต้องใช้ Token)
Route::get('/profiles/{username}', [ProfileController::class, 'showPublic']);

Route::put('/profiles/{username}/test-update', [ProfileController::class, 'updateForTest']);

// Protected Routes (ต้องส่ง Bearer Token)
// สมมติว่าใช้ Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [ProfileController::class, 'showMyProfile']);
    Route::put('/user/profile', [ProfileController::class, 'update']);

});
