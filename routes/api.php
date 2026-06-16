<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;


// POST Request , /register URL ที่เราเปิดไว้ให้ฝั่ง Frontend เรียกใช้งาน และเมื่อมีการเรียกใช้งาน URL นี้ จะให้ไปทำงานที่ฟังก์ชัน register ใน AuthController
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);