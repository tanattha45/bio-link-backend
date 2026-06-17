<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    // ดึงข้อมูลตัวเอง (ต้อง Login)
    public function showMyProfile(Request $request)
    {
        $profile = $request->user()->profile;
        if (!$profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }
        return new ProfileResource($profile);
    }

    // อัปเดตข้อมูล (ต้อง Login)
    public function update(UpdateProfileRequest $request)
    {
        $profile = $request->user()->profile;
        $profile->update($request->validated());
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => new ProfileResource($profile)
        ], 200);
    }

    // ดึงข้อมูลสำหรับหน้าสาธารณะ (ไม่ต้อง Login)
    public function showPublic($username)
    {
        // ค้นหาข้อมูลจาก username
        $profile = Profile::where('username', $username)->first();

        // ถ้าหาไม่เจอ ให้แจ้งเตือนกลับไป
        if (!$profile) {
            return response()->json([
                'message' => 'ไม่พบข้อมูล Profile ของผู้ใช้นี้'
            ], 404);
        }

        // ถ้าหาเจอ ให้ส่งข้อมูลกลับไปในรูปแบบ ProfileResource
        return new ProfileResource($profile);
    }

    public function updateForTest(Request $request, $username)
    {
        try {
            // 1. 🔓 ปลดล็อก Mass Assignment ชั่วคราว (ไม่ง้อ $fillable ใน Model แล้ว)
            \App\Models\Profile::unguard();

            // 2. ตรวจสอบหรือสร้าง User ID 1 มารองรับ Foreign Key
            $user = \App\Models\User::firstOrCreate(
                ['id' => 1],
                [
                    'name' => 'Tanattha Test',
                    'email' => 'test@example.com',
                    'password' => bcrypt('password')
                ]
            );

            // 3. 🔍 ส่องโครงสร้างตาราง profiles ใน MySQL จริงๆ ณ ตอนนี้ว่ามีคอลัมน์อะไรอยู่บ้าง
            $existingColumns = \Illuminate\Support\Facades\Schema::getColumnListing('profiles');

            // 4. รวบรวมข้อมูลดิบทั้งหมดที่ส่งมาจากหน้าเว็บ React
            $incomingData = [
                'user_id'           => $user->id,
                'username'          => $username,
                'display_name'      => $request->input('display_name'),
                'bio'               => $request->input('bio'),
                'avatar_url'        => $request->input('avatar_url'),
                'cover_url'         => $request->input('cover_url'),
                'contact_name'      => $request->input('contact_name'),
                'contact_phone'     => $request->input('contact_phone'),
                'contact_email'     => $request->input('contact_email'),
                'contact_company'   => $request->input('contact_company'),
                'contact_job_title' => $request->input('contact_job_title'),
                'contact_website'   => $request->input('contact_website'),
                'show_save_contact' => $request->input('show_save_contact', 1),
            ];

            // 💡 กรองเอาเฉพาะข้อมูลที่มีคอลัมน์อยู่จริงใน MySQL เท่านั้น (อันไหนไม่มีในตารางจะถูกคัดออกอัตโนมัติ ไม่พังแน่นอน)
            $dataToSave = [];
            foreach ($incomingData as $key => $value) {
                if (in_array($key, $existingColumns) && $value !== null) {
                    $dataToSave[$key] = $value;
                }
            }

            // 5. สั่งอัปเดตข้อมูลหรือสร้างใหม่ลงฐานข้อมูล
            $profile = \App\Models\Profile::updateOrCreate(
                ['username' => $username],
                $dataToSave
            );

            // 🔒 เปิดระบบความปลอดภัย Model กลับคืนตามเดิม
            \App\Models\Profile::reguard();

            // 6. ส่ง JSON ผลลัพธ์กลับไปหาหน้าบ้าน React ทันที
            return response()->json([
                'success' => true,
                'message' => 'บันทึกข้อมูลผ่านโหมด Expert สำเร็จเรียบร้อย!',
                'data' => $profile
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error_from_backend' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}