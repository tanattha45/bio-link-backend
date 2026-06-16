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
        // 1. ค้นหา Profile จากชื่อ username
        $profile = Profile::where('username', $username)->firstOrFail();
        
        // 2. รับข้อมูล JSON จาก Postman และอัปเดตลง Database
        $profile->update($request->all());
        
        // 3. ส่งข้อมูลที่อัปเดตเสร็จแล้วกลับไปให้ Postman แสดงผล
        return response()->json([
            'message' => 'อัปเดตข้อมูลสำเร็จผ่าน Postman!',
            'data' => new ProfileResource($profile)
        ], 200);
    }
}