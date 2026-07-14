<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Block;
use Illuminate\Support\Facades\Storage; // สำหรับจัดการไฟล์
use Illuminate\Support\Str; // สำหรับสุ่มชื่อไฟล์

use Illuminate\Support\Facades\Http;

class BlockController extends Controller
{
    // ฟังก์ชันสร้างข้อมูลใหม่ (POST)
    public function store(Request $request)
    {
        // ดึงข้อมูล User ที่กำลังล็อกอินอยู่ในปัจจุบันผ่าน Token
        $user = auth()->user(); 

        // ถ้า User นี้ยังไม่มี Profile ให้ส่ง Error กลับไป 
        if (!$user->profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลโปรไฟล์ กรุณาสร้างโปรไฟล์ก่อนค่ะ'
            ], 400);
        }

        // ตรวจสอบความถูกต้องของข้อมูล 
        // บังคับให้ content_data ต้องเป็น Array เพื่อให้ Laravel แปลงเป็น JSON ได้สมบูรณ์
        $validated = $request->validate([
            'type' => 'required|string|in:LINK,IMAGE,VIDEO,SLIDER,SHOP',
            'title' => 'nullable|string|max:255',
            'content_data' => 'nullable|array' 
        ]);

        $cleanContentData = $this->processImages($validated['content_data'] ?? []);

        // บันทึกข้อมูลบล็อกลงฐานข้อมูล
        $block = Block::create([
            'profile_id' => $user->profile->id, 
            'type' => $validated['type'], 
            'title' => $validated['title'],
            'content_data' => $cleanContentData, // ถ้าไม่มีข้อมูลส่งมา ให้ใส่เป็น Array ว่างรอไว้
            'is_visible' => true,
            'display_order' => 1
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'สร้างข้อมูลใหม่สำเร็จ!',
            'data' => $block
        ], 201); 
    }

    // ฟังก์ชันส่งข้อมูลไปให้ React (GET - ใช้ตอนเปิดหน้า EditLink ครั้งแรก)
    public function show($id)
    {
        $user = auth()->user();

        // ค้นหาบล็อกตาม ID แต่ ต้องเป็นบล็อกของ Profile คนที่ล็อกอินอยู่เท่านั้น
        // ถ้าเป็น ID ของคนอื่น ระบบจะขึ้น 404 ออกมาอัตโนมัติ 
        $block = Block::where('profile_id', $user->profile->id)->findOrFail($id); 

        return response()->json([
            'status' => 'success',
            'data' => $block
        ]);
    }

    // ฟังก์ชันรับข้อมูลจาก React มาอัปเดตลง Database (PUT - ตอนกดปุ่ม Save)
    public function update(Request $request, $id)
    {
        try {
            $user = auth()->user();

            // 1. ⭐️ แก้ไข: หาข้อมูล Block โดยใช้ profile_id แทน user_id ⭐️
            $block = Block::where('profile_id', $user->profile->id)->findOrFail($id);

            // 2. อัปเดตเฉพาะฟิลด์ที่ React ส่งมาเท่านั้น
            if ($request->has('is_visible')) {
                $block->is_visible = $request->input('is_visible');
            }

            if ($request->has('title')) {
                $block->title = $request->input('title');
            }

            if ($request->has('type')) {
                $block->type = $request->input('type');
            }

            if ($request->has('content_data')) {
                // ⭐️ เพิ่มเติม: กรณีมีการส่งข้อมูลข้างในบล็อกมาใหม่ ให้ผ่านตัวจัดการรูปภาพด้วย
                $oldContentData = $block->content_data ?? [];
                $cleanContentData = $this->processImages($request->input('content_data'), $oldContentData);
                $block->content_data = $cleanContentData;
            }

            $block->save();

            return response()->json([
                'success' => true,
                'message' => 'อัปเดตข้อมูลบล็อกสำเร็จ',
                'data' => $block
            ], 200);

        } catch (\Exception $e) {
            // ดักจับ Error ไว้ส่งกลับไปบอกหน้าบ้าน จะได้รู้ว่าพังเพราะอะไร
            return response()->json([
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการอัปเดต',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ฟังก์ชันลบข้อมูล (DELETE)
    public function destroy($id)
    {
        $user = auth()->user();

        // ค้นหาบล็อกที่ต้องการลบ และต้องเป็นของ Profile คนที่ล็อกอินอยู่เท่านั้น
        $block = Block::where('profile_id', $user->profile->id)->findOrFail($id);

        // สั่งลบรูปภาพที่ค้างอยู่ในบล็อกนี้ออกจากเซิร์ฟเวอร์ก่อน
        $contentData = $block->content_data ?? [];
        if (is_array($contentData)) {
            foreach ($contentData as $item) {
                if (!empty($item['image']) && str_contains($item['image'], '/storage/')) {
                    $path = str_replace('/storage/', '', $item['image']);
                    Storage::disk('public')->delete($path);
                }
            }
        }

        // สั่งลบออกจาก Database
        $block->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'ลบข้อมูลบล็อกสำเร็จ!'
        ], 200);
    }

    // ฟังก์ชันดึงข้อมูลบล็อกทั้งหมดของ User คนนั้น (GET)
    public function index()
    {
        $user = auth()->user();

        // ถ้ายังไม่มีโปรไฟล์ ให้ส่ง Array ว่างๆ กลับไป
        if (!$user->profile) {
            return response()->json([
                'status' => 'success',
                'data' => []
            ]);
        }

        // ดึงบล็อกทั้งหมดของโปรไฟล์นี้
        $blocks = Block::where('profile_id', $user->profile->id)
                       ->orderBy('display_order', 'asc') // เรียงตามลำดับ
                       ->get();

        return response()->json([
            'status' => 'success',
            'data' => $blocks
        ]);
    }

    // อัปเกรดฟังก์ชันจัดการรูปภาพ (บีบอัด, แปลง WebP, ลบรูปเก่า)
    private function processImages($newContentData, $oldContentData = [])
    {
        if (!is_array($newContentData)) return [];

        // 1. หารูปภาพเก่าทั้งหมดที่เคยมีในบล็อกนี้
        $oldImageUrls = [];
        if (is_array($oldContentData)) {
            foreach ($oldContentData as $item) {
                if (!empty($item['image']) && str_contains($item['image'], '/storage/')) {
                    $oldImageUrls[] = $item['image'];
                }
            }
        }

        $cleanContentData = [];
        $newImageUrls = [];

        foreach ($newContentData as $key => $item) {
            // เช็คว่ามีคีย์ image และเป็นข้อความ Base64 หรือไม่ (รูปที่เพิ่งอัปโหลดใหม่)
            if (isset($item['image']) && preg_match('/^data:image\/(\w+);base64,/', $item['image'])) {
                
                // ดึงข้อมูล Base64 เพียวๆ ออกมา
                $base64Data = substr($item['image'], strpos($item['image'], ',') + 1);
                $decodedData = base64_decode($base64Data);
                
                // ใช้ Intervention Image ย่อขนาดและแปลงเป็น WebP
                $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
                $image = $manager->read($decodedData);
                
                // ย่อความกว้างให้เหลือไม่เกิน 800px (ป้องกันไฟล์ใหญ่เกินจำเป็น)
                $image->scaleDown(width: 800);
                
                // แปลงเป็น WebP พร้อมบีบอัดคุณภาพ 80%
                $encoded = $image->toWebp(80);

                // สร้างชื่อไฟล์ใหม่ไม่ให้ซ้ำกัน 
                $fileName = 'blocks/' . Str::uuid() . '.webp';

                // บันทึกไฟล์ลงโฟลเดอร์ของ Laravel (storage/app/public/blocks)
                Storage::disk('public')->put($fileName, (string) $encoded);

                // เปลี่ยนค่าใน array จาก Base64 ยาวๆ ให้เป็น URL สั้นๆ
                $item['image'] = url('/storage/' . $fileName);
                $newImageUrls[] = $item['image'];
            } 
            // ถ้ารูปที่ส่งมาเป็น URL เดิมอยู่แล้ว (ไม่ได้แก้ไขรูป)
            elseif (!empty($item['image']) && str_contains($item['image'], '/storage/')) {
                $newImageUrls[] = $item['image'];
            }

            $cleanContentData[$key] = $item;
        }

        // 2. หาส่วนต่างของรูป (ถ้ารูปเก่าไม่มีในรายการใหม่ แปลว่าผู้ใช้กดลบทิ้ง)
        $urlsToDelete = array_diff($oldImageUrls, $newImageUrls);
        foreach ($urlsToDelete as $url) {
            $path = str_replace('/storage/', '', $url);
            Storage::disk('public')->delete($path);
        }
        
        return $cleanContentData;
    }

    public function resolveTikTokUrl(Request $request)
    {
        $url = $request->query('url');

        if (!$url) {
            return response()->json(['error' => 'URL is required'], 400);
        }

        try {
            // ใช้ Http facade ของ Laravel ในการตามลิงก์ Redirect
            // เพิ่ม User-Agent ปลอมตัวเป็นเบราว์เซอร์ปกติ ป้องกัน TikTok บล็อก Request
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ])->get($url);

            // ดึง URL สุดท้ายหลังจากระบบ Follow Redirect แล้ว
            $finalUrl = (string) $response->effectiveUri();

            return response()->json([
                'status' => 'success',
                'fullUrl' => $finalUrl
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error' => 'Cannot resolve URL', 
                'details' => $e->getMessage()
            ], 500);
        }
    }
}