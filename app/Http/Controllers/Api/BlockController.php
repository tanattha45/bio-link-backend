<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Block;
use Illuminate\Support\Facades\Storage; // สำหรับจัดการไฟล์
use Illuminate\Support\Str; // สำหรับสุ่มชื่อไฟล์

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
            'type' => 'required|string', 
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
        $user = auth()->user();

        // ค้นหาบล็อกที่จะแก้ และต้องเป็นของ Profile ตัวเองเท่านั้น 
        $block = Block::where('profile_id', $user->profile->id)->findOrFail($id);

        // ตรวจสอบข้อมูลก่อนเอาไปอัปเดต
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content_data' => 'nullable|array'
        ]);

        $cleanContentData = $this->processImages($validated['content_data'] ?? []);

        // อัปเดตข้อมูลทับของเดิม 
        $block->update([
            'title' => $validated['title'],
            'content_data' => $cleanContentData
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'บันทึกข้อมูลสำเร็จ!',
            'data' => $block
        ]);
    }

    // ฟังก์ชันลบข้อมูล (DELETE)
    public function destroy($id)
    {
        $user = auth()->user();

        // ค้นหาบล็อกที่ต้องการลบ และต้องเป็นของ Profile คนที่ล็อกอินอยู่เท่านั้น
        $block = Block::where('profile_id', $user->profile->id)->findOrFail($id);

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

    private function processImages($contentData)
    {
        if (!is_array($contentData)) return [];

        foreach ($contentData as $key => $item) {
            // เช็คว่ามีคีย์ image และเป็นข้อความ Base64 หรือไม่
            if (isset($item['image']) && preg_match('/^data:image\/(\w+);base64,/', $item['image'], $type)) {
                
                // ดึงข้อมูล Base64 เพียวๆ ออกมา (ตัดส่วนหัว data:image/png;base64, ออก)
                $base64Data = substr($item['image'], strpos($item['image'], ',') + 1);
                $decodedData = base64_decode($base64Data);
                
                // สร้างชื่อไฟล์ใหม่ไม่ให้ซ้ำกัน 
                $extension = strtolower($type[1]); // ดึงนามสกุลไฟล์ เช่น png, jpg
                $fileName = 'blocks/' . Str::uuid() . '.' . $extension;

                // บันทึกไฟล์ลงโฟลเดอร์ของ Laravel (storage/app/public/blocks)
                Storage::disk('public')->put($fileName, $decodedData);

                // เปลี่ยนค่าใน array จาก Base64 ยาวๆ ให้เป็น URL สั้นๆ
                $contentData[$key]['image'] = '/storage/' . $fileName;
            }
        }
        
        return $contentData;
    }
}