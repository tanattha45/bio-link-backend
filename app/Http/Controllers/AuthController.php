<?php

// ระบุที่อยู่ของไฟล์นี้ในโปรเจกต์
namespace App\Http\Controllers;

// เรียกใช้ Model ของตาราง users เพื่อจัดการข้อมูลในฐานข้อมูล
use App\Models\User;

// ใช้สำหรับรับค่าต่าง ๆ ที่ส่งมาจาก HTTP Request
use Illuminate\Http\Request;

// เข้ารหัสลับ (Hashing)
use Illuminate\Support\Facades\Hash;

// ตรวจสอบความถูกต้องของข้อมูล (Data Validation)
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{

    // ฟังก์ชันสำหรับการสมัครสมาชิก (Register)
    public function register(Request $request)
    {
        // 1. การตรวจสอบข้อมูล (Validation)
        $validator = Validator::make($request->all(), [
            // required ห้ามเป็นค่าว่าง, string ต้องเป็นข้อความ, max:100 ความยาวไม่เกิน 100 ตัวอักษร
            'display_name' => 'required|string|max:100',

            // unique:users ตรวจสอบในตาราง users ต้องไม่เป็นค่าซ้ำ
            'username'     => 'required|string|max:50|unique:users',

            // email: ตรวจสอบว่าเป็นรูปแบบอีเมลที่ถูกต้อง
            'email'        => 'required|string|email|max:255|unique:users',

            'password'     => 'required|string|min:8',
        ]);

        // Validation Fails
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. การสร้างผู้ใช้ใหม่ (Create User)

        // INSERT INTO users
        $user = User::create([
            'display_name' => $request->display_name,
            'username'     => $request->username,
            'email'        => $request->email,
            'password'     => Hash::make($request->password), // เข้ารหัสรหัสผ่าน
        ]);

        // 3. การตอบกลับ (Response)
        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'user' => $user,
        ], 201);
    }

    // ฟังก์ชันสำหรับการเข้าสู่ระบบ (Login)
    public function login(Request $request)
    {
        // 1. ตรวจสอบว่ากรอกอีเมลและรหัสผ่านมาไหม
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Validation Fails
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. ค้นหา User จากอีเมล
        $user = User::where('email', $request->email)->first();

        // 3. เช็กว่าเจอ User ไหม และรหัสผ่านตรงกันหรือเปล่า
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
            ], 401); // 401 คือ Unauthorized (ไม่มีสิทธิ์)
        }

        // 3. การสร้าง Token สำหรับการยืนยันตัวตน (Create Token)
        $token = $user->createToken('auth_token')->plainTextToken;

        // 4. การตอบกลับ (Response)
        return response()->json([
            'status' => 'success',
            'message' => 'User logged in successfully',
            'access_token' => $token,
            'user' => $user,
            'token_type' => 'Bearer',
        ], 200);
    }
}
