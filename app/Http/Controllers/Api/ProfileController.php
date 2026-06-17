<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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

    // ฟังก์ชันทดสอบอัปเดตข้อมูลพร้อมระบบอัปโหลดไฟล์รูปภาพของจริง
    public function testUpdate(Request $request, $username)
    {
        try {
            // 1. 🔓 ปลดล็อก Mass Assignment ชั่วคราว
            \App\Models\Profile::unguard();

            // 2. ตรวจสอบหรือสร้าง User ID 1 มารองรับ Foreign Key (สำหรับทดสอบ)
            $user = \App\Models\User::firstOrCreate(
                ['id' => 1],
                [
                    'name' => 'Tanattha Test',
                    'email' => 'test@example.com',
                    'password' => bcrypt('password')
                ]
            );

            // 3. ✨ ระบบจัดการไฟล์รูปภาพ (File Upload) ✨
            $avatarUrl = $request->input('avatar_url'); // รับค่า URL เดิมมาก่อน (ถ้ามี)
            if ($request->hasFile('avatar')) {
                // ถ้ามีการแนบไฟล์รูป avatar มา ให้เซฟลงโฟลเดอร์ storage/app/public/avatars
                $path = $request->file('avatar')->store('avatars', 'public');
                $avatarUrl = '/storage/' . $path; // สร้าง Path ใหม่สำหรับเก็บลง Database
            }

            // (เผื่ออนาคต) ระบบจัดการอัปโหลดภาพปก
            $coverUrl = $request->input('cover_url');
            if ($request->hasFile('cover')) {
                $path = $request->file('cover')->store('covers', 'public');
                $coverUrl = '/storage/' . $path;
            }

            // 4. 🔍 ส่องโครงสร้างตาราง profiles ใน MySQL ว่ามีคอลัมน์อะไรอยู่บ้าง
            $existingColumns = Schema::getColumnListing('profiles');

            // 5. รวบรวมข้อมูลดิบทั้งหมดที่ส่งมาจากหน้าเว็บ React
            $incomingData = [
                'user_id'           => $user->id,
                'username'          => $username,
                'display_name'      => $request->input('display_name'),
                'bio'               => $request->input('bio'),
                'avatar_url'        => $avatarUrl, // ใช้ URL ที่ผ่านการเช็กไฟล์อัปโหลดแล้ว
                'cover_url'         => $coverUrl,  // ใช้ URL ที่ผ่านการเช็กไฟล์อัปโหลดแล้ว
                'contact_name'      => $request->input('contact_name'),
                'contact_phone'     => $request->input('contact_phone'),
                'contact_email'     => $request->input('contact_email'),
                'contact_company'   => $request->input('contact_company'),
                'contact_job_title' => $request->input('contact_job_title'),
                'contact_website'   => $request->input('contact_website'),
                'show_save_contact' => $request->input('show_save_contact', 1),
            ];

            // 6. 💡 กรองเอาเฉพาะข้อมูลที่มีคอลัมน์อยู่จริงใน MySQL เท่านั้น 
            $dataToSave = [];
            foreach ($incomingData as $key => $value) {
                if (in_array($key, $existingColumns) && $value !== null) {
                    $dataToSave[$key] = $value;
                }
            }

            // 7. สั่งอัปเดตข้อมูลหรือสร้างใหม่ลงฐานข้อมูล
            $profile = \App\Models\Profile::updateOrCreate(
                ['username' => $username],
                $dataToSave
            );

            // 8. 🔒 เปิดระบบความปลอดภัย Model กลับคืนตามเดิม
            \App\Models\Profile::reguard();

            // 9. ส่ง JSON ผลลัพธ์กลับไปหาหน้าบ้าน React
            return response()->json([
                'success' => true,
                'message' => 'บันทึกข้อมูลและรูปภาพสำเร็จเรียบร้อย!',
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