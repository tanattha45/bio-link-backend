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
        $profile = Profile::where('username', $username)->first();

        if (!$profile) {
            return response()->json([
                'message' => 'ไม่พบข้อมูล Profile ของผู้ใช้นี้'
            ], 404);
        }

        return new ProfileResource($profile);
    }

    // ฟังก์ชันนี้ถูกเรียกโดย Frontend ผ่าน Route test-update
    public function updateForTest(Request $request, $username)
    {
        try {
            \App\Models\Profile::unguard();

            $user = \App\Models\User::firstOrCreate(
                ['username' => $username],
                [
                    'display_name' => $request->input('display_name') ?? $username,
                    'email'        => $username . '@example.com', // จำลองอีเมลไม่ให้ซ้ำกัน
                    'password'     => bcrypt('password')
                ]
            );

            // ✨ ระบบอัปโหลดไฟล์รูปภาพของจริง ✨
            $avatarUrl = $request->input('avatar_url');
            if ($request->hasFile('avatar')) {
                $path = $request->file('avatar')->store('avatars', 'public');
                $avatarUrl = '/storage/' . $path;
            }

            $coverUrl = $request->input('cover_url');
            if ($request->hasFile('cover')) {
                $path = $request->file('cover')->store('covers', 'public');
                $coverUrl = '/storage/' . $path;
            }

            $bgImageUrl = $request->input('bg_image_url');
            if ($request->hasFile('bg_image')) {
                $path = $request->file('bg_image')->store('backgrounds', 'public');
                $bgImageUrl = '/storage/' . $path;
            }

            $existingColumns = Schema::getColumnListing('profiles');

            $incomingData = [
                'user_id'           => $user->id,
                'username'          => $username,
                'display_name'      => $request->input('display_name'),
                'bio'               => $request->input('bio'),
                'avatar_url'        => $avatarUrl,
                'cover_url'         => $coverUrl,
                'bg_image_url'      => $bgImageUrl,
                'contact_name'      => $request->input('contact_name'),
                'contact_phone'     => $request->input('contact_phone'),
                'contact_email'     => $request->input('contact_email'),
                'contact_company'   => $request->input('contact_company'),
                'contact_job_title' => $request->input('contact_job_title'),
                'contact_website'   => $request->input('contact_website'),
                'show_save_contact' => $request->input('show_save_contact', 1),
            ];

            $dataToSave = [];
            foreach ($incomingData as $key => $value) {
                if (in_array($key, $existingColumns) && $value !== null) {
                    $dataToSave[$key] = $value;
                }
            }

            $profile = \App\Models\Profile::updateOrCreate(
                ['username' => $username],
                $dataToSave
            );

            \App\Models\Profile::reguard();

            return response()->json([
                'success' => true,
                'message' => 'บันทึกข้อมูลและอัปโหลดรูปภาพสำเร็จเรียบร้อย!',
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