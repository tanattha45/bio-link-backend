<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage; 

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
        $profile = Profile::with('blocks')->where('username', $username)->first();

        if (!$profile) {
            return response()->json([
                'message' => 'ไม่พบข้อมูล Profile ของผู้ใช้นี้'
            ], 404);
        }

        return new ProfileResource($profile);
    }

    // ฟังก์ชันลบไฟล์รูปเก่าโดยเฉพาะ (Garbage Collection)
    private function deleteOldImage($oldUrl)
    {
        if ($oldUrl && str_contains($oldUrl, '/storage/')) {
            $parts = explode('/storage/', $oldUrl);
            $oldPath = end($parts);
            Storage::disk('public')->delete($oldPath);
        }
    }

    // ฟังก์ชันตัวช่วย: จัดการรูปภาพ (ย่อขนาด, แปลง WebP)
    private function processAndSaveImage($file, $folder, $oldUrl, $maxWidth)
    {
        $this->deleteOldImage($oldUrl);

        $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
        $image = $manager->read($file);
        
        $image->scaleDown(width: $maxWidth);
        $encoded = $image->toWebp(80);

        $filename = $folder . '/' . uniqid() . '.webp';
        Storage::disk('public')->put($filename, (string) $encoded);

        return '/storage/' . $filename;
    }

    // ⭐️ แก้ไขฟังก์ชันนี้: ดักจับการอัปเดตเพื่อไม่ให้รูปหายเวลากดเซฟอย่างอื่น ⭐️
    private function resolveImageUrl($request, $existingUrl, $fileKey, $urlKey, $folder, $maxWidth)
    {
        // 1. ถ้ามีไฟล์อัปโหลดเข้ามาจริง ให้เซฟเป็นรูปใหม่ทับไปเลย
        if ($request->hasFile($fileKey)) {
            return $this->processAndSaveImage($request->file($fileKey), $folder, $existingUrl, $maxWidth);
        }

        // 2. ⭐️ แก้ไขใหม่: ถ้า React ไม่ได้ส่งคีย์มาเลย (เช่น กดเซฟเฉยๆ ไม่ได้ยุ่งกับรูป)
        // ต้อง "คืนค่ารูปเดิม" ห้ามลบทิ้งเด็ดขาด!
        if (!$request->has($urlKey) && !$request->has($fileKey)) {
            return $existingUrl; 
        }

        // 3. กรณีที่ส่งคีย์มา ให้เช็คค่าข้างในเพื่อดูว่าผู้ใช้สั่งลบจริงไหม
        $inputUrl = $request->input($urlKey);
        
        // ถ้าจงใจส่งเป็นข้อความว่างๆ, null หรือ undefined มา ถึงจะแปลว่าสั่งลบทิ้งของจริง
        if ($inputUrl === '' || $inputUrl === null || $inputUrl === 'null' || $inputUrl === 'undefined') {
            $this->deleteOldImage($existingUrl);
            return null;
        }

        // ถ้าเจอลิงก์ blob: ของ React ค้างอยู่ ให้เพิกเฉยแล้วใช้รูปเดิมเพื่อกันบั๊กรูปแตก
        if (str_starts_with($inputUrl, 'blob:')) {
            return $existingUrl;
        }

        // ผ่านทุกด่านมาได้ แสดงว่าเป็น URL รูปเดิมที่ไม่ได้ถูกแก้
        return $inputUrl;
    }

    // ฟังก์ชันนี้ถูกเรียกโดย Frontend ผ่าน Route test-update
    public function updateForTest(Request $request, $username) 
    {
        try {
            \App\Models\Profile::unguard();

            $newUsername = $request->input('username', $username);
            $user = \App\Models\User::where('username', $username)->first();
            
            if ($user) {
                $user->username = $newUsername;
                if ($request->has('display_name')) {
                    $user->display_name = $request->input('display_name');
                }
                $user->save();
            } else {
                $user = \App\Models\User::create([
                    'username' => $newUsername,
                    'display_name' => $request->input('display_name') ?? $newUsername,
                    'email' => $newUsername . '@example.com',
                    'password' => bcrypt('password')
                ]);
            }

            $existingProfile = \App\Models\Profile::where('username', $username)->first();

            // --- ส่วนจัดการรูปภาพ (ใช้ฟังก์ชัน resolveImageUrl ที่แก้ไขแล้ว) ---
            $avatarUrl = $this->resolveImageUrl($request, optional($existingProfile)->avatar_url, 'avatar', 'avatar_url', 'avatars', 400);
            $coverUrl = $this->resolveImageUrl($request, optional($existingProfile)->cover_url, 'cover', 'cover_url', 'covers', 1200);
            $bgImageUrl = $this->resolveImageUrl($request, optional($existingProfile)->bg_image_url, 'bg_image', 'bg_image_url', 'backgrounds', 1920);

            $existingColumns = \Illuminate\Support\Facades\Schema::getColumnListing('profiles');

            $incomingData = [
                'user_id'           => $user->id,
                'username'          => $newUsername, 
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
                'theme_config'      => $request->input('theme_config'),
            ];

            $dataToSave = [];
            foreach ($incomingData as $key => $value) {
                if (in_array($key, $existingColumns)) {
                    if (in_array($key, ['avatar_url', 'cover_url', 'bg_image_url'])) {
                        $dataToSave[$key] = $value;
                    } elseif ($value !== null) {
                        $dataToSave[$key] = $value;
                    }
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