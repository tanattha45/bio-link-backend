<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Profile;
use App\Models\Block;
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

            // ⭐️ ปิดการทำงานระบบดักจับ Anti-Spam ชั่วคราวเพื่อให้เทสได้ง่าย
            /*
            $recentRecord = Analytic::where('profile_id', $profile->id)
                ->where('session_id', $sessionId)
                ->where('block_id', $blockId)
                ->where('created_at', '>=', Carbon::now()->subMinutes(30))
                ->first();

            if (!$recentRecord) {
            */
            
                // บันทึกข้อมูลลงฐานข้อมูลทุกครั้งที่มีการกดคลิกทันที
                Analytic::create([
                    'profile_id' => $profile->id,
                    'block_id'   => $blockId,
                    'session_id' => $sessionId,
                    'ip_address' => $hashedIp,
                    'user_agent' => $request->header('User-Agent'),
                    'referrer_url' => $safeReferrer,
                ]);

            /*
            }
            */

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

        // =========================================================
        // ⭐️ ย้ายการรับค่าวันที่ขึ้นมาด้านบนสุด เพื่อให้กรองข้อมูลได้ทั้งหมด
        // =========================================================
        
        $range = $request->query('range', '7days');
        $startDate = Carbon::now()->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        $daysCount = 7;

        if ($range === 'today') {
            $startDate = Carbon::now()->startOfDay();
            $daysCount = 1;
        } elseif ($range === '7days') {
            $startDate = Carbon::now()->subDays(6)->startOfDay();
            $daysCount = 7;
        } elseif ($range === '30days') {
            $startDate = Carbon::now()->subDays(29)->startOfDay();
            $daysCount = 30;
        } elseif ($range === 'custom') {
            $startInput = $request->query('start');
            $endInput = $request->query('end');
            
            if ($startInput && $endInput) {
                $startDate = Carbon::parse($startInput)->startOfDay();
                $endDate = Carbon::parse($endInput)->endOfDay();
                // แก้บั๊กจำนวนวันเพี้ยน โดยจับเริ่ม 00:00 ทั้งคู่มาเทียบกัน
                $daysCount = max(1, $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1);
            } else {
                $startDate = Carbon::now()->subDays(6)->startOfDay();
                $daysCount = 7;
            }
        }

        // =========================================================
        // ⭐️ คำนวณวันที่ของ "ช่วงก่อนหน้า" (Previous Period) 
        // =========================================================
        $prevEndDate = $startDate->copy()->subSecond(); // วินาทีสุดท้ายก่อนเริ่ม startDate
        $prevStartDate = $startDate->copy()->subDays($daysCount)->startOfDay();

        // =========================================================
        // ⭐️ นำ $startDate และ $endDate มาใช้กรองข้อมูลในการ์ดทั้ง 5 ใบ
        // =========================================================

        // ยอดวิว (กรองตามวันที่)
        $totalViews = Analytic::where('profile_id', $profile->id)
            ->whereNull('block_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        
        // ยอดคลิก (กรองตามวันที่)
        $totalClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
            
        // ยอดกด Save Contact (กรองตามวันที่)
        $totalSaves = Analytic::where('profile_id', $profile->id)
            ->where('block_id', 999999)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        
        $ctr = $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 1) : 0;

        // =========================================================
        // ⭐️ --- ดึงข้อมูลช่วงก่อนหน้า (เพื่อเอามาเทียบ %) ---
        // =========================================================
        
        $prevViews = Analytic::where('profile_id', $profile->id)
            ->whereNull('block_id')
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->count();
            
        $prevClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->count();
            
        $prevSaves = Analytic::where('profile_id', $profile->id)
            ->where('block_id', 999999)
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->count();
            
        $prevCtr = $prevViews > 0 ? round(($prevClicks / $prevViews) * 100, 1) : 0;

        // ⭐️ ฟังก์ชันคำนวณ % การเติบโต
        $calcTrend = function($current, $prev) {
            if ($prev == 0) return $current > 0 ? 100 : 0; // ถ้าของเก่าเป็น 0 และของใหม่มีค่า ให้ถือว่าโต 100%
            return round((($current - $prev) / $prev) * 100, 1);
        };

        $trend = [
            'views'  => $calcTrend($totalViews, $prevViews),
            'clicks' => $calcTrend($totalClicks, $prevClicks),
            'saves'  => $calcTrend($totalSaves, $prevSaves),
            'ctr'    => $calcTrend($ctr, $prevCtr),
        ];

        // =========================================================
        // ⭐️ ระบบกราฟ
        // =========================================================
        
        // 1. ดึงข้อมูล View (บังคับจัดกลุ่มวันตาม Timezone ไทย ป้องกันวันเหลื่อม)
        $viewsData = Analytic::where('profile_id', $profile->id)
            ->whereNull('block_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));

        // 2. ดึงข้อมูล Click (บังคับจัดกลุ่มวันตาม Timezone ไทย ป้องกันวันเหลื่อม)
        $clicksData = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));

        // 3. เตรียมข้อมูลใส่ Array ให้ตรงกับวันที่
        $chartData = [];
        $thaiDays = [
            'Sunday' => 'อา', 'Monday' => 'จ', 'Tuesday' => 'อ', 
            'Wednesday' => 'พ', 'Thursday' => 'พฤ', 'Friday' => 'ศ', 'Saturday' => 'ส'
        ];

        // เปลี่ยนมาใช้การ "นับเดินหน้า" (+0, +1, +2 วัน) จาก startDate 
        for ($i = 0; $i < $daysCount; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $dayName = $thaiDays[Carbon::parse($date)->format('l')];
            
            // ดึงจำนวนออกมา (ถ้าไม่มีให้เป็น 0)
            $viewsCount = $viewsData->has($date) ? $viewsData->get($date)->count() : 0;
            $clicksCount = $clicksData->has($date) ? $clicksData->get($date)->count() : 0;

            $chartData[] = [
                'date' => $date,
                'day_name' => $dayName,
                'views' => $viewsCount,
                'clicks' => $clicksCount 
            ];
        }

        // =========================================================
        // ⭐️ ประสิทธิภาพลิงก์ (กรองตามวันที่)
        // =========================================================
        
        // ดึงบล็อกทั้งหมดของโปรไฟล์นี้มา
        $blocks = Block::where('profile_id', $profile->id)->get();
        
        // นับยอดคลิกแยกรหัสบล็อก (ดึงจาก Analytic และกรองตามวันที่)
        $blockClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$startDate, $endDate]) // ⭐️ เพิ่มตัวกรองตรงนี้
            ->select('block_id', DB::raw('count(*) as total'))
            ->groupBy('block_id')
            ->pluck('total', 'block_id');

        // เอาข้อมูลบล็อกมารวมกับยอดคลิก
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
                'trend'        => $trend, // ⭐️ ส่งก้อน Trend กลับไปให้หน้าบ้าน
                'chart_data'   => $chartData,
                'links'        => $linksPerformance, 
            ]
        ]);
    }
}