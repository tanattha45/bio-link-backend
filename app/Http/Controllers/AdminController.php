<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserBannedMail;

class AdminController extends Controller
{
    public function getUsers()
    {
        // ใช้ get() ดึงข้อมูลมาก่อน
        $users = User::orderBy('created_at', 'desc')->get();

        $formattedUsers = $users->map(function($user) {
            
            // 1. ป้องกันชื่อเป็น Null 
            $displayName = $user->display_name ? $user->display_name : 'User';
            
            // 2. ป้องกันวันที่พัง (ถ้าเกิด Laravel มองมันเป็นแค่ข้อความ ไม่ใช่วัตถุเวลา)
            $dateFormatted = '-';
            if ($user->created_at) {
                // เช็คว่าใช้คำสั่ง format() ได้ไหม ถ้าได้ก็ใช้ ถ้าไม่ได้ให้ดึงมาแสดงแบบดิบๆ เลย
                $dateFormatted = is_object($user->created_at) 
                    ? $user->created_at->format('d/m/Y') 
                    : date('d/m/Y', strtotime($user->created_at));
            }

            return [
                'id' => $user->id,
                'name' => $displayName, 
                'username' => $user->username ?? '-',
                'email' => $user->email ?? '-',
                'role' => $user->role ?? 'user',
                'status' => $user->status ?? 'active',
                'avatar' => $user->avatar ?? 'https://ui-avatars.com/api/?name='.urlencode($displayName),
                'date' => $dateFormatted // 🌟 ใช้วันที่ที่ผ่านเกราะป้องกันแล้ว
            ];
        });

        return response()->json(['status' => 'success', 'users' => $formattedUsers], 200);
    }

    public function updateRole(Request $request, $id)
    {
        if ($id == auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'ไม่อนุญาตให้เปลี่ยนสิทธิ์บัญชีของตนเองได้'], 403);
        }

        $user = User::findOrFail($id);
        $user->role = $request->role; 
        $user->save();

        return response()->json(['status' => 'success', 'message' => 'อัปเดตสิทธิ์เรียบร้อย'], 200);
    }

    public function toggleStatus($id)
    {
        try {
            // 1. เช็คสิทธิ์
            if ($id == auth()->id()) {
                return response()->json(['status' => 'error', 'message' => 'ไม่อนุญาตให้แบนตนเอง'], 403);
            }

            // 2. ใช้ find แทน findOrFail เพื่อเช็คว่าเจอไหมก่อน
            $user = User::find($id);

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'ไม่พบผู้ใช้งานรายนี้'], 404);
            }

            // 3. สลับสถานะและจัดการ Token
            if ($user->status === 'active') {
                $user->status = 'banned';
                Mail::to($user->email)->queue(new UserBannedMail($user));
                
                // ลบ Token ทั้งหมดของ User คนนี้ เพื่อเตะออกจากระบบทันที
                $user->tokens()->delete(); 
            } else {
                $user->status = 'active';
            }
            
            $user->save();

            return response()->json(['status' => 'success', 'message' => 'อัปเดตสถานะเรียบร้อย']);

        } catch (\Exception $e) {
            // ตรงนี้สำคัญมาก ถ้ามันพัง มันจะตอบกลับมาว่าพังเพราะอะไร แทนที่จะเป็น 500 เฉยๆ
            return response()->json([
                'status' => 'error', 
                'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteUser($id)
    {
        if ($id == auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'ไม่อนุญาตให้ลบบัญชีของตนเองได้'], 403);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['status' => 'success', 'message' => 'ลบผู้ใช้เรียบร้อย'], 200);
    }
}