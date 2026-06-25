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

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\URL;
use App\Mail\VerifyEmailMail;

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

        // 3. สร้าง Temporary Signed URL (ลิงก์ยืนยันตัวตนแบบจำกัดเวลา 60 นาที)
        $expireMinutes = config('auth.verification.expire', 60);

        $temporaryVerificationUrl = URL::temporarySignedRoute(
            'verification.verify', 
            now()->addMinutes($expireMinutes), 
            [
                'id' => $user->id, 
                'hash' => sha1($user->email)
            ]
        );

        // 4. ส่งอีเมลพร้อมลิงก์ 
        try {
            Mail::to($user->email)->queue(new VerifyEmailMail($temporaryVerificationUrl));
        } catch (\Exception $e) {
            // กรณีส่งอีเมลไม่สำเร็จ สามารถเลือกลบ User ทิ้ง หรือคืนค่า error กลับไปได้
            // ในที่นี้จะปล่อยให้สมัครผ่านไปก่อน แต่ค่อยให้กด Resend Email ทีหลังได้
        }

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
        // 1. สร้างกุญแจจำแนกตัวบุคคล (ใช้อีเมล/username + ไอพีแอดเดรสรวมกัน)
        // ดึงข้อมูลเช็คเงื่อนไขว่าถ้าามีส่ง email มาให้ใช้ email แต่ถ้าไม่มีให้ไปเอา username มาใช้แทน
        $identifier = $request->input('email') ?? $request->input('username'); 
        // นำชื่อบัญชีมาต่อกับ IP Address เพื่อดูว่ามีการพยายามเข้าสู่ระบบรัวๆมั้ย
        // $throttleKey เรานิยามให้มันเป็นป้ายชื่อ
        $throttleKey = Str::lower($identifier) . '|' . $request->ip();

        // 2. เช็คว่าคนนี้โดนบล็อกอยู่หรือเปล่า? (จำกัด 5 ครั้ง)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            // คำนวณเวลาที่เหลือ (วินาที)
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = ceil($seconds / 60);

            return response()->json([
                'status' => 'error',
                'message' => "คุณใส่รหัสผิดหลายครั้งเกินไป กรุณารอ {$minutes} นาที แล้วลองใหม่ค่ะ"
            ], 429); // 429 คือ Too Many Requests
        }

        // 3. ตรวจสอบว่ารหัสผ่านมาไหม 
        $validator = Validator::make($request->all(), [
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 4. ค้นหา User
        if ($request->has('email')){
            $user = User::where('email', $request->email)->first();
        } else {
            $user = User::where('username' , $request->username)->first();
        }
        
        // ตรวจสอบรหัสผ่าน และ บันทึกความผิดพลาด
        if (!$user || !Hash::check($request->password, $user->password)) {
            
            // ถ้าเรารหัสผิดเกน 5 ครั้งตามที่เราตั้งในข้อที่ 2 จะโดนบล็อค
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'status' => 'error',
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
            ], 401);
        }

        // 5. ตรวจสอบสถานะการยืนยันอีเมล
        // is_null เช็คว่าคอลัมน์นี้ในฐานข้อมูลยังว่างอยู่มั้ยถ้าใช่ = ยังไม่เคยคลิกลิงก์ยืนยันเลย
        if (is_null($user->email_verified_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'บัญชีของคุณยังไม่ได้ยืนยันอีเมล กรุณาตรวจสอบกล่องจดหมายของคุณเพื่อคลิกลิงก์ยืนยัน',
                'is_verified' => false // ส่งค่าไปให้หน้าบ้าน จะได้แสดงปุ่ม "ส่งอีเมลใหม่อีกครั้ง" ได้
            ], 403); // 403 Forbidden 
        }

        // login สำเร็จ ล้างประวัติ และออก Token
        RateLimiter::clear($throttleKey);

        $token = $user->createToken('auth_token')->plainTextToken;

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
        $otp = random_int(1000, 9999);

        // บันทึกรหัสลงหน่วยความจำ Cache ตั้งเวลาหมดอายุไว้ที่ 5 นาที (300 วินาที)
        // ตั้งชื่อคีย์ตามอีเมล เช่น 'otp_tanattha@gmail.com' เพื่อป้องกันข้อมูลสลับกันคนอื่น
        Cache::put('otp_' . $request->email, $otp, 300);

        try {
            //สั่งส่งอีเมลจำลองออกไปหาผู้ใช้ (ปลายทางจะไปโผล่ที่ Mailtrap ของเรา)
            Mail::to($request->email)->queue(new OtpMail($otp));

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

    // ฟังก์ชันส่งอีเมลยืนยันอีกครั้ง
    public function resendVerification(Request $request)
    {
        // 1. ตรวจสอบข้อมูลอีเมลที่ส่งมาจากหน้าบ้าน
        $request->validate([
            'email' => 'required|email'
        ]);

        // 2. ป้องกันการกดรัวๆ - ให้กดได้ 1 ครั้งต่อ 1 นาทีต่อ 1 IP
        $throttleKey = 'resend-email|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status' => 'error',
                'message' => "กรุณารออีก {$seconds} วินาทีก่อนที่จะกดส่งอีเมลใหม่อีกครั้ง"
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60); // บันทึกการกด และจำกัดเวลา 60 วินาที

        // 3. ค้นหาผู้ใช้
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบอีเมลนี้ในระบบ'
            ], 404);
        }

        // 4. ถ้าอีเมลนี้ยืนยันไปแล้ว ไม่ต้องส่งซ้ำ
        if ($user->hasVerifiedEmail()) { // หรือใช้ is_null($user->email_verified_at) == false
            return response()->json([
                'status' => 'error',
                'message' => 'อีเมลนี้ได้รับการยืนยันเรียบร้อยแล้ว สามารถเข้าสู่ระบบได้เลยค่ะ'
            ], 400);
        }

        // 5. สร้าง Signed URL ใหม่ 

        // ระบบจะไปหา expire ใน config/auth.php ก่อนหากหาไม่เจอถึงจะมาหยิบตัวเลข
        $expireMinutes = config('auth.verification.expire', 60);

        $temporaryVerificationUrl = URL::temporarySignedRoute(
            'verification.verify', 
            now()->addMinutes($expireMinutes), 
            [
                'id' => $user->id, 
                'hash' => sha1($user->email)
            ]
        );

        // 6. ส่งอีเมลเข้า Queue
        Mail::to($user->email)->queue(new VerifyEmailMail($temporaryVerificationUrl));

        return response()->json([
            'status' => 'success',
            'message' => 'ระบบได้ส่งลิงก์ยืนยันตัวตนใหม่ไปยังอีเมลของคุณแล้วค่ะ'
        ], 200);
    }

    // ฟังก์ชันรับลิงก์จากอีเมลเพื่อยืนยัน
    public function verifyEmail(Request $request, $id, $hash)
{
    if (!$request->hasValidSignature()) {
        return redirect(
            env('FRONTEND_URL') . '/login?verified=expired'
        );
    }

    $user = User::findOrFail($id);

    if (!hash_equals((string)$hash, sha1($user->email))) {
        return redirect(
            env('FRONTEND_URL') . '/login?verified=invalid'
        );
    }

    if (!$user->hasVerifiedEmail()) {
        $user->email_verified_at = now();
        $user->save();
    }

    return redirect(
        env('FRONTEND_URL') . '/login?verified=success'
    );
}
}