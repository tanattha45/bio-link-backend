<?php

// ระบุที่อยู่ของไฟล์นี้ในโปรเจกต์
namespace App\Http\Controllers;

// เรียกใช้ Model ของตาราง users เพื่อจัดการข้อมูลในฐานข้อมูล
use App\Models\User;

// ใช้สำหรับรับค่าต่าง ๆ ที่ส่งมาจาก HTTP Request เก็บในตัวแปร $request
use Illuminate\Http\Request;

// เข้ารหัสลับ (Hashing)
use Illuminate\Support\Facades\Hash;

// ตรวจสอบความถูกต้องของข้อมูล (Data Validation)
use Illuminate\Support\Facades\Validator;

use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{

    // ฟังก์ชันสำหรับการสมัครสมาชิก (Register)
    public function register(Request $request)
    {
        // 1. การตรวจสอบข้อมูล (Validation)
        // $validator คือตัวแปรที่เก็บผลลัพธ์จากการ Validation แล้ว 
        // $request->all() $requestมันเก็บข้อมูลแล้ว all หมายถึงเราจะดึงข้อมูลทุกตัวที่มันเก็บมาเช็ค
        $validator = Validator::make($request->all(), [
            // required ห้ามเป็นค่าว่าง, string ต้องเป็นข้อความ, max:100 ความยาวไม่เกิน 100 ตัวอักษร
            'display_name' => 'required|string|max:100',

            // unique:users ตรวจสอบในตาราง users ต้องไม่เป็นค่าซ้ำ
            'username'     => 'required|string|max:50|unique:users',

            // email: ตรวจสอบว่าเป็นรูปแบบอีเมลที่ถูกต้อง
            'email'        => 'required|string|email|max:255|unique:users',

            'password'     => 'required|string|min:8',
        ]);

        // Validation Fails คืนค่าเป็น Boolean 
        // ถ้า fail (ข้อมูลไม่ถูก) จะคืนค่า true จะเข้า if 
        // ถ้า fail (ข้อมูลถูก) จะคืนค้า false แล้วข้ามไปทำงานอื่นต่อ
        if ($validator->fails()) {

            // case ข้อมูลผิดจะ return ค่าเหล่านี้กลับไปให้ frontend 
            return response()->json([
                'status' => 'error',
                'message' => 'Validation Error',

                // ดึงข้อมูลที่ผิดทั้งหมดแสดงผลในรูปของ json คืนให้frontend
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
        // 1. ตรวจสอบว่ารหัสผ่านมาไหม
        $validator = Validator::make($request->all(), [
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

        // 2. ค้นหา User โดยดูว่า React ส่ง email หรือ username มา
        if ($request->has('email')){
            $user = User::where('email', $request->email)->first();
        } else {
            $user = User::where('username' , $request->username)->first();
        }
        
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

    // การส่ง OTP
    public function sendOtp(Request $request)
    {
        // ตรวจข้อมูล email
        $request->validate([
            'email' => 'required|email'
        ]);

        // check is that email have in database
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบอีเมลนี้ในระบบ กรุณาตรวจสอบความถูกต้องอีกครั้งค่ะ'
            ], 404);
        }

        // สุ่ม opt 4 หลัก
        $otp = rand(1000,9999);

        // บันทึกรหัสลงหน่วยความจำ Cache ตั้งเวลาหมดอายุไว้ที่ 5 นาที (300 วินาที)
        // ตั้งชื่อคีย์ตามอีเมล เช่น 'otp_tanattha@gmail.com' เพื่อป้องกันข้อมูลสลับกันคนอื่น
        Cache::put('otp_' . $request->email, $otp, 300);

        try {
            //สั่งส่งอีเมลจำลองออกไปหาผู้ใช้ (ปลายทางจะไปโผล่ที่ Mailtrap ของเรา)
            Mail::to($request->email)->send(new OtpMail($otp));

            return response()->json([
                'status' => 'success',
                'message' => 'ส่งรหัส OTP ไปยังอีเมลของท่านเรียบร้อยแล้วค่ะ'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดจากระบบส่งอีเมล: ' . $e->getMessage()
            ], 500);
        }
    }

    public function verifyOtp(Request $request){
        // ตรวจสอบข้อมูลที่ React ส่งมา
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|numeric|digits:4'
        ]);

        //ดึงรหัส OTP ของอีเมลนี้ที่ถูกบันทึกไว้ในหน่วยความจำ (Cache) ออกมา
        $cachedOtp = Cache::get('otp_' . $request->email);

        //กรณีหาไม่เจอ
        if (!$cachedOtp) {
            return response()->json([
                'status'  => 'error',
                'message' => 'รหัส OTP หมดอายุ หรือยังไม่ได้ทำการขอรหัสค่ะ'
            ], 400);
        }

        // กรณีรหัสที่พิมพ์มา ไม่ตรงกับรหัสที่ส่งไปในอีเมล
        if ($cachedOtp != $request->otp) {
            return response()->json([
                'status'  => 'error',
                'message' => 'รหัส OTP ไม่ถูกต้อง กรุณาลองใหม่อีกครั้งค่ะ'
            ], 400);
        }
    

        // กรณีถูกต้อง
        return response()->json([
            'status'  => 'success',
            'message' => 'ยืนยันรหัส OTP สำเร็จ!'
        ], 200);
    }

    // reset password
    public function resetPassword(Request $request)
    {
        // ตรวจสอบข้อมูล
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|numeric|digits:4',
            // confirmed จะตรวจสอบฟิลที่อยู่ใน format _confirmation ให้ทันทีดังนั้นเราไม่จำเป็นที่จะต้องเช็คซ้ำ
            // front เราใช้ password_confirmation
            'password' => 'required|string|min:8|confirmed',
        ]);

        // ตรวจสอบรหัส OTP ใน Cache
        $cachedOtp = Cache::get('otp_' . $request->email);

        if (!$cachedOtp || $cachedOtp != $request->otp) {
            return response()->json([
                'status'  => 'error',
                'message' => 'เซสชันหมดอายุ หรือรหัส OTP ไม่ถูกต้อง กรุณาเริ่มทำรายการใหม่อีกครั้งค่ะ'
            ], 400);
        }

        // ค้นหา User คนนี้ในฐานข้อมูล
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'ไม่พบข้อมูลผู้ใช้งานในระบบ'
            ], 404);
        }

        // อัปเดตรหัสผ่านใหม่
        $user->password = Hash::make($request->password);
        $user->save();

        // ลบตาราง OTP ออกจาก Cache เพื่อไม่ให้ใช้ซ้ำ
        Cache::forget('otp_' . $request->email);

        return response()->json([
            'status'  => 'success',
            'message' => 'เปลี่ยนรหัสผ่านใหม่สำเร็จ'
        ], 200);
    }

    // Login with google
    public function googleLogin(Request $request)
    {
        // การใช้ฟักืชั่น try คือการป้องกันการเกิด error ถ้าหากมี error มันจะข้ามไปทำในส่วนของ catch ทันที
        try {
            // ตรวจสอบ token กับ google
            // userFromToken เอา Token ที่ได้จาก React ยิงส่งไปถาม Server ของ Google
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->token);

            // ตรวจสอบกับฐานข้อมูล
            // $googleUser->getEmail() การดึง email of user from google
            $user = User::where('email', $googleUser->getEmail())->first();


            // เป็นผู้ใช้ใหม่ 
            if (!$user) {
                // ดึงชื่อหน้า @ จากอีเมลมาทำเป็น Username 
                $emailParts = explode('@', $googleUser->getEmail());
                $baseUsername = $emailParts[0];
                $Username = $baseUsername;
                $counter = 1;

                // เช็คชื่อซ้ำก่อน ถ้าซ้ำค่อยเติมเลข 1, 2, 3
                while (User::where('username', $Username)->exists()) {
                    $Username = $baseUsername . $counter;
                    $counter++;
                }

                $user = User::create([
                    'display_name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'username' => $Username,
                    'google_id' => $googleUser->getID(),
                    // สุ่ม password เพราะผู้ใช้จะไม่ได้ใช้รหัสนี้ในการ login 
                    'password' => bcrypt(\Illuminate\Support\Str::random(24))
                ]);
            }

            // เป็นผู้ใช้เก่า
            else{
                // อัปเดตฟิลด์ google_id ของผู้ใช้คนนั้นให้เป็นไอดีล่าสุดที่ได้มาจาก Google
                $user->update(['google_id' => $googleUser->getID()]);
            }

            // ออก Token ของระบบเราเองให้ React เอาไปใช้งาน
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'user' => $user,
                'token' => $token
            ], 200);
        } 
        
        catch (\Exception $e) {
            return response()->json([
                'message' => 'เข้าสู่ระบบด้วย Google ไม่สำเร็จ', 
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function logout(Request $request)
    {
        try {
            // สั่งทำลาย Token ตัวปัจจุบันที่ผู้ใช้คนนี้ถืออยู่ทิ้งไป
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'ออกจากระบบสำเร็จ'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการออกจากระบบ',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}