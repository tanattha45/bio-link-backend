<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * 1. บันทึกสถิติ (เข้าชมโปรไฟล์ หรือ คลิกปุ่มลิงก์)
     */
    public function track(Request $request, $username)
    {
        $profile = Profile::where('username', $username)->first();
        
        if (!$profile) {
            return response()->json(['message' => 'ไม่พบข้อมูลโปรไฟล์'], 404);
        }

        $sessionId = $request->input('session_id');
        $blockId = $request->input('block_id'); // ถ้าเป็น null แปลว่ายอด View หน้าเว็บ / ถ้ามีค่าแปลว่า Click
        $ipAddress = $request->ip();

        // 🛡️ ป้องกันสแปมยอดวิว: เช็คว่าไอดีเซสชันนี้ เคยกดปุ่มนี้หรือดูหน้านี้ไปแล้วหรือยังใน 30 นาทีที่ผ่านมา
        $recentRecord = Analytic::where('profile_id', $profile->id)
            ->where('session_id', $sessionId)
            ->where('block_id', $blockId)
            ->where('created_at', '>=', Carbon::now()->subMinutes(30))
            ->first();

        if (!$recentRecord) {
            // บันทึกสถิติใหม่ (ความปลอดภัยสูง: เข้ารหัส IP Address เพื่อความเป็นส่วนตัว)
            Analytic::create([
                'profile_id' => $profile->id,
                'block_id'   => $blockId,
                'session_id' => $sessionId,
                'ip_address' => hash('sha256', $ipAddress),
                'user_agent' => $request->header('User-Agent'),
                'referrer_url' => $request->input('referrer_url'),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * 2. ดึงข้อมูลสถิติไปแสดงผลบนหน้า Dashboard ของเจ้าของโปรไฟล์
     */
    public function getDashboardStats(Request $request)
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return response()->json(['message' => 'ไม่พบข้อมูลโปรไฟล์ของผู้ใช้งาน'], 404);
        }

        // ยอดผู้เข้าชมทั้งหมด (นับตอน block_id เป็นค่าว่าง)
        $totalViews = Analytic::where('profile_id', $profile->id)->whereNull('block_id')->count();
        
        // ยอดการคลิกลิงก์ทั้งหมด (นับตอน block_id มีการส่งค่ามา)
        $totalClicks = Analytic::where('profile_id', $profile->id)->whereNotNull('block_id')->count();
        
        // คำนวณหาค่าเฉลี่ยอัตราการคลิก (CTR)
        $ctr = $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 1) : 0;

        // 📊 สเต็ปเคลียร์ปัญหากราฟแท่งแหว่ง/ฟันหลอ (7 วันย้อนหลัง)
        
        // สเต็ปที่ 2.1: สร้างโครงสร้าง Array รอรับข้อมูล 7 วัน (ใส่ค่าเริ่มต้นยอดวิวเป็น 0 ทั้งหมด)
        $skeletonData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateString = Carbon::now()->subDays($i)->format('Y-m-d');
            $skeletonData[$dateString] = 0;
        }

        // สเต็ปที่ 2.2: ดึงข้อมูลยอดผู้เข้าชม (Views) จริงจากฐานข้อมูลย้อนหลัง 7 วัน
        $sevenDaysAgo = Carbon::now()->subDays(6)->startOfDay();
        $dbData = Analytic::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->where('profile_id', $profile->id)
            ->whereNull('block_id') 
            ->where('created_at', '>=', $sevenDaysAgo)
            ->groupBy('date')
            ->get();

        // สเต็ปที่ 2.3: นำตัวเลขจากฐานข้อมูลไปหยอดใส่ในวันที่มีคนเข้าดู (วันไหนไม่มีคนดูจะคงค่า 0 ไว้ตามเดิม)
        foreach ($dbData as $row) {
            if (isset($skeletonData[$row->date])) {
                $skeletonData[$row->date] = (int)$row->count;
            }
        }

        // สเต็ปที่ 2.4: แปลง Format และใส่ชื่อย่อวันภาษาไทย (จ, อ, พ) ส่งไปให้หน้าบ้านวาดกราฟได้ทันที
        $chartData = [];
        $thaiDays = [
            'Sunday' => 'อา', 'Monday' => 'จ', 'Tuesday' => 'อ', 
            'Wednesday' => 'พ', 'Thursday' => 'พฤ', 'Friday' => 'ศ', 'Saturday' => 'ส'
        ];

        foreach ($skeletonData as $date => $count) {
            $dayNameEnglish = Carbon::parse($date)->format('l'); // แปลงวันที่เป็นคำศัพท์ เช่น 'Monday'
            $chartData[] = [
                'date' => $date,
                'day_name' => $thaiDays[$dayNameEnglish], // ดึงตัวย่อภาษาไทยมาใส่ให้เรียบร้อย
                'views' => $count
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_views'  => $totalViews,
                'total_clicks' => $totalClicks,
                'ctr'          => $ctr,
                'chart_data'   => $chartData, // ข้อมูลจัดเรียงสวยงามครบถ้วน 7 วัน
            ]
        ]);
    }
}