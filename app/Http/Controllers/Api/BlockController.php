<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Block; 

class BlockController extends Controller
{
    // ฟังก์ชันสร้างข้อมูลใหม่ (POST)
    public function store(Request $request)
    {
        // ดึงข้อมูล User ที่กำลังล็อกอินอยู่ในปัจจุบันผ่าน Token
        $user = auth()->user(); 

        // [เพิ่มใหม่] เช็คกันเหนียว: ถ้า User นี้ยังไม่มี Profile ให้ส่ง Error กลับไป (ป้องกันระบบพัง)
        if (!$user->profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลโปรไฟล์ กรุณาสร้างโปรไฟล์ก่อนค่ะ'
            ], 400);
        }

        // [เพิ่มใหม่] ตรวจสอบความถูกต้องของข้อมูล (Validation) 
        // บังคับให้ content_data ต้องเป็น Array เพื่อให้ Laravel แปลงเป็น JSON ได้สมบูรณ์
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content_data' => 'nullable|array' 
        ]);

        // บันทึกข้อมูลบล็อกลงฐานข้อมูล
        $block = Block::create([
            'profile_id' => $user->profile->id, 
            'type' => 'LINK',
            'title' => $validated['title'],
            'content_data' => $validated['content_data'] ?? [], // ถ้าไม่มีข้อมูลส่งมา ให้ใส่เป็น Array ว่างรอไว้
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

        // [แก้ใหม่] ค้นหาบล็อกตาม ID แต่ "ต้องเป็นบล็อกของ Profile คนที่ล็อกอินอยู่เท่านั้น!"
        // ถ้าเป็น ID ของคนอื่น ระบบจะพ่น 404 ออกมาอัตโนมัติ (ปลอดภัยขึ้น 100%)
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

        // [แก้ใหม่] ค้นหาบล็อกที่จะแก้ และต้องเป็นของ Profile ตัวเองเท่านั้น
        $block = Block::where('profile_id', $user->profile->id)->findOrFail($id);

        // [เพิ่มใหม่] ตรวจสอบข้อมูลก่อนเอาไปอัปเดต
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content_data' => 'nullable|array'
        ]);

        // อัปเดตข้อมูลทับของเดิม 
        $block->update([
            'title' => $validated['title'],
            'content_data' => $validated['content_data']
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'บันทึกข้อมูลสำเร็จ!',
            'data' => $block
        ]);
    }
}