<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Profile;
use App\Models\Block; // ⭐️ เพิ่ม Import Model Block เข้ามา
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    // 1. เก็บสถิติเมื่อมีคนเข้าชมหน้าโปรไฟล์ (View) หรือ กดลิงก์ (Click) หรือ กด Save Contact
    public function track(Request $request, $username)
    {
        try {
            $profile = Profile::where('username', $username)->first();
            
            if (!$profile) {
                return response()->json(['message' => 'Profile not found'], 404);
            }

            $sessionId = $request->input('session_id');
            $blockId = $request->input('block_id');
            $ipAddress = $request->ip();

            // เข้ารหัส IP แล้วตัดความยาวให้เหลือแค่ 45 ตัวอักษร (ป้องกัน DB ล้น)
            $hashedIp = $ipAddress ? substr(hash('sha256', $ipAddress), 0, 45) : null;

            // ตัดความยาว URL ของ referrer ไม่ให้เกิน 255 ตัวอักษร
            $referrer = $request->input('referrer_url');
            $safeReferrer = $referrer ? substr($referrer, 0, 255) : null;

            // เช็คว่าภายใน 30 นาทีที่ผ่านมา เซสชันนี้ทำกิจกรรมนี้ไปแล้วหรือยัง (ป้องกันปั่นสถิติ)
            $recentRecord = Analytic::where('profile_id', $profile->id)
                ->where('session_id', $sessionId)
                ->where('block_id', $blockId)
                ->where('created_at', '>=', Carbon::now()->subMinutes(30))
                ->first();

            if (!$recentRecord) {
                Analytic::create([
                    'profile_id' => $profile->id,
                    'block_id'   => $blockId,
                    'session_id' => $sessionId,
                    'ip_address' => $hashedIp,
                    'user_agent' => $request->header('User-Agent'),
                    'referrer_url' => $safeReferrer,
                ]);
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการบันทึกสถิติ: ' . $e->getMessage()
            ], 500);
        }
    }

    // 2. ดึงสถิติไปโชว์ที่หน้า Dashboard
    public function getDashboardStats(Request $request)
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        // ยอดวิวทั้งหมด (block_id เป็น null)
        $totalViews = Analytic::where('profile_id', $profile->id)->whereNull('block_id')->count();
        
        // ยอดคลิกลิงก์ทั่วไป (block_id มีค่า และไม่ใช่รหัส Save Contact)
        $totalClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->count();
            
        // ยอดกด Save Contact (block_id เป็น 999999)
        $totalSaves = Analytic::where('profile_id', $profile->id)
            ->where('block_id', 999999)
            ->count();
        
        $ctr = $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 1) : 0;

        // --- ระบบกราฟ 7 วัน (ไม่มีฟันหลอ และแก้ปัญหา Timezone) ---
        $skeletonData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateString = Carbon::now()->subDays($i)->format('Y-m-d');
            $skeletonData[$dateString] = 0;
        }

        $sevenDaysAgo = Carbon::now()->subDays(6)->startOfDay();
        
        // ดึงข้อมูลมาก่อน แล้วใช้ Collection ของ Laravel จัดกลุ่มตามวันที่
        $analyticsData = Analytic::where('profile_id', $profile->id)
            ->whereNull('block_id') 
            ->where('created_at', '>=', $sevenDaysAgo)
            ->get();

        // จับกลุ่มข้อมูลตามวันที่แบบเป๊ะๆ ด้วย Carbon
        $groupedData = $analyticsData->groupBy(function($item) {
            return $item->created_at->format('Y-m-d');
        });

        // เอาจำนวนที่นับได้ไปหยอดใส่โครงกราฟ 7 วันที่เราเตรียมไว้
        foreach ($skeletonData as $date => $count) {
            if ($groupedData->has($date)) {
                $skeletonData[$date] = $groupedData->get($date)->count();
            }
        }

        $chartData = [];
        $thaiDays = [
            'Sunday' => 'อา', 'Monday' => 'จ', 'Tuesday' => 'อ', 
            'Wednesday' => 'พ', 'Thursday' => 'พฤ', 'Friday' => 'ศ', 'Saturday' => 'ส'
        ];

        foreach ($skeletonData as $date => $count) {
            $dayNameEnglish = Carbon::parse($date)->format('l'); 
            $chartData[] = [
                'date' => $date,
                'day_name' => $thaiDays[$dayNameEnglish],
                'views' => $count
            ];
        }

        // =========================================================
        // ⭐️ 3. เพิ่มระบบดึงประสิทธิภาพลิงก์ (โชว์ทุกลิงก์ แม้คลิกจะเป็น 0)
        // =========================================================
        
        // 3.1 ดึงบล็อกทั้งหมดของโปรไฟล์นี้มา
        $blocks = Block::where('profile_id', $profile->id)->get();
        
        // 3.2 นับยอดคลิกแยกรหัสบล็อก (ดึงจาก Analytic แบบ Group By จะไวกว่ามาก)
        $blockClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->select('block_id', DB::raw('count(*) as total'))
            ->groupBy('block_id')
            ->pluck('total', 'block_id');

        // 3.3 เอาข้อมูลบล็อกมารวมกับยอดคลิก
        $linksPerformance = $blocks->map(function ($block) use ($blockClicks) {
            // ถอดรหัส JSON ของ content_data ถ้าจำเป็น
            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            
            // พยายามหา URL มาโชว์ (ดึงจาก item แรก)
            $url = '';
            if (is_array($contentData) && count($contentData) > 0) {
                $url = $contentData[0]['url'] ?? $contentData[0]['link'] ?? '';
            }

            return [
                'id' => $block->id,
                'title' => $block->title ?: 'ไม่มีชื่อลิงก์',
                'url' => $url,
                // ถ้ายอดคลิกไม่มีใน Analytics ให้ใส่ 0 เข้าไปเลย
                'clicks' => $blockClicks[$block->id] ?? 0 
            ];
        })
        ->sortByDesc('clicks') // เรียงจากคลิกมากสุดไปน้อยสุด
        ->values() // รีเซ็ต index ของอาร์เรย์ใหม่
        ->toArray();

        // =========================================================

        return response()->json([
            'success' => true,
            'data' => [
                'total_views'  => $totalViews,
                'total_clicks' => $totalClicks,
                'total_saves'  => $totalSaves,
                'ctr'          => $ctr,
                'chart_data'   => $chartData,
                'links'        => $linksPerformance, // ⭐️ ส่งข้อมูลลิงก์ไปให้ React
            ]
        ]);
    }
}