<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\Profile;
use App\Models\Block;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Exports\AnalyticsExport;
use Maatwebsite\Excel\Facades\Excel;

class AnalyticsController extends Controller
{
    // 1. เก็บสถิติเมื่อมีคนเข้าชมหน้าโปรไฟล์ (View) หรือ กดลิงก์ (Click) หรือ กด Save Contact
    public function track(Request $request, $username)
    {
        \Illuminate\Support\Facades\Log::info('🎯 [เช็คคลิก SLIDER] หน้าบ้านส่งอะไรมา:', $request->all());
        try {
            $profile = Profile::where('username', $username)->first();
            
            if (!$profile) {
                return response()->json(['message' => 'Profile not found'], 404);
            }

            $sessionId = $request->input('session_id');
            $blockId = $request->input('block_id');
            // 🌟 รับค่า URL ที่ถูกคลิกจากหน้าบ้าน
            $clickedUrl = $request->input('clicked_url'); 
            
            $ipAddress = $request->ip();
            $hashedIp = $ipAddress ? substr(hash('sha256', $ipAddress), 0, 45) : null;
            
            $referrer = $request->input('referrer_url');
            $safeReferrer = $referrer ? substr($referrer, 0, 255) : null;

            Analytic::create([
                'profile_id' => $profile->id,
                'block_id'   => $blockId,
                'clicked_url'=> $clickedUrl, // 🌟 บันทึกลงฐานข้อมูล
                'session_id' => $sessionId,
                'ip_address' => $hashedIp,
                'user_agent' => $request->header('User-Agent'),
                'referrer_url' => $safeReferrer,
            ]);

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
        
        // 1. ดึงข้อมูล View (บังคับจัดกลุ่มวันตาม Timezone ไทย)
        $viewsData = Analytic::where('profile_id', $profile->id)
            ->whereNull('block_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));

        // 🌟 2. ดึงข้อมูล Click และ "กรองข้อมูล (Filter)" ก่อนเอาไปลงกราฟ
        $rawChartClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();
            
        // โหลดข้อมูล Block เพื่อใช้ตรวจสอบ
        $tempBlocks = Block::where('profile_id', $profile->id)->get()->keyBy('id');
        
        $tempCleanUrl = function($u) {
            if (empty($u)) return '';
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        // ทำการกรอง (Filter) ให้เหลือเฉพาะคลิกที่ตรงกับ URL จริงๆ
        $filteredChartClicks = $rawChartClicks->filter(function($click) use ($tempBlocks, $tempCleanUrl) {
            $block = $tempBlocks->get($click->block_id);
            if (!$block) return false;

            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $tempCleanUrl($click->clicked_url) === $tempCleanUrl($url)) return true;
                }
            } else {
                $url = trim($block->url ?? '');
                if (!empty($url) && $tempCleanUrl($click->clicked_url) === $tempCleanUrl($url)) return true;
            }
            return false;
        });

        // 🌟 จัดกลุ่มข้อมูลใส่กราฟ จากข้อมูลที่ผ่านการกรองแล้วเท่านั้น
        $clicksData = $filteredChartClicks->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));

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
        // 🌟 3. ประสิทธิภาพลิงก์ (แก้ใหม่ให้จับคู่แม่นยำ 100% + เพิ่มรูป/ไอคอน)
        // =========================================================
        
        $blocks = Block::where('profile_id', $profile->id)->get();
        
        // ดึงยอดคลิกมาทั้งหมดแบบดิบๆ ก่อน
        $allClicks = Analytic::where('profile_id', $profile->id)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $linksPerformance = collect();

        // 🌟 ฟังก์ชันทำความสะอาด URL ฝั่งหลังบ้าน (พระเอกของงานนี้)
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            // ตัด https://, http://, www. และ / ตัวท้ายสุดออกให้หมด
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        foreach ($blocks as $block) {
            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;

            // 🌟 3.1 บล็อกกล่องกลุ่ม (SHOP, SLIDER) ให้แยกเป็นรายชิ้น
            if (in_array(strtoupper($block->type), ['SHOP', 'SLIDER']) && is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (empty($url)) continue; // ข้ามลิงก์ว่าง

                    // นับยอดคลิก โดยแปลงให้เป็น String ทั้งคู่ และเทียบ URL แบบสะอาด
                    $clicks = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                        $idMatch = (string)$click->block_id === (string)$block->id;
                        $urlMatch = $cleanUrl($click->clicked_url) === $cleanUrl($url);
                        return $idMatch && $urlMatch;
                    })->count();

                    $linksPerformance->push([
                        'id' => $block->id,
                        'title' => $item['name'] ?? $item['title'] ?? $block->title ?? 'ไม่มีชื่อ',
                        'url' => $url,
                        // 🌟 ดึงรูปภาพและไอคอน เพื่อให้ Slider โชว์รูปได้เหมือน Shop
                        'image' => $item['image'] ?? $item['imageUrl'] ?? null,
                        'icon' => $item['icon'] ?? $item['iconId'] ?? $block->icon ?? 'Link',
                        'clicks' => $clicks
                    ]);
                }
            } 
            // 🌟 แก้ไขตรงส่วน 3.2 บล็อกเดี่ยว (LINK)
            else {
                if (is_array($contentData) && count($contentData) > 0) {
                    foreach ($contentData as $item) {
                        $url = trim($item['url'] ?? $item['link'] ?? '');
                        if (empty($url)) continue;

                        // 🌟 จุดสำคัญ: ดึง iconId ของรายการนั้นๆ ออกมา (ถ้าไม่มีให้ไปเอาจากบล็อกหลัก)
                        $itemIcon = $item['iconId'] ?? $item['icon'] ?? $block->icon ?? 'Link';

                        $clicks = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                            return (string)$click->block_id === (string)$block->id && 
                                $cleanUrl($click->clicked_url) === $cleanUrl($url);
                        })->count();

                        $linksPerformance->push([
                            'id' => $block->id,
                            'title' => $item['name'] ?? $item['title'] ?? $block->title ?? 'ไม่มีชื่อ',
                            'url' => $url,
                            'image' => $item['image'] ?? $item['imageUrl'] ?? $block->image ?? null,
                            'icon' => $itemIcon, // 🌟 ส่งชื่อไอคอนที่ถูกต้องไป
                            'clicks' => $clicks
                        ]);
                    }
                }
            }
        }
        // เรียงลำดับจากคลิกมากสุดไปน้อยสุด แล้วแปลงกลับเป็น Array
        $linksPerformance = $linksPerformance->sortByDesc('clicks')->values()->toArray();
        // 🌟 เพิ่มบรรทัดนี้: รวมยอดคลิกใหม่จากรายการด้านล่าง
        $calculatedTotalClicks = collect($linksPerformance)->sum('clicks');

        // =========================================================

        return response()->json([
            'success' => true,
            'data' => [
                'total_views'  => $totalViews,
                'total_clicks' => $calculatedTotalClicks, // 🌟 เปลี่ยนมาใช้ค่าที่คำนวณใหม่ตรงนี้
                'total_saves'  => $totalSaves,
                'ctr'          => $ctr,
                'trend'        => $trend,
                'chart_data'   => $chartData,
                'links'        => $linksPerformance, 
            ]
        ]);
    }

    // =========================================================
    // 📥 ดาวน์โหลดรายงานสถิติเป็น Excel (เวอร์ชันดักจับ Error)
    // =========================================================
    public function exportReport(Request $request)
    {
        try {
            $profile = $request->user()->profile;

            if (!$profile) {
                return response()->json(['message' => 'Profile not found'], 404);
            }

            // 1. ดึงวันที่ที่ผู้ใช้เลือกมาจาก URL
            $range = $request->query('range', '7days');
            $startDate = \Illuminate\Support\Carbon::now()->startOfDay();
            $endDate = \Illuminate\Support\Carbon::now()->endOfDay();

            if ($range === 'today') {
                $startDate = \Illuminate\Support\Carbon::now()->startOfDay();
            } elseif ($range === '7days') {
                $startDate = \Illuminate\Support\Carbon::now()->subDays(6)->startOfDay();
            } elseif ($range === '30days') {
                $startDate = \Illuminate\Support\Carbon::now()->subDays(29)->startOfDay();
            } elseif ($range === 'custom') {
                $startInput = $request->query('start');
                $endInput = $request->query('end');
                if ($startInput && $endInput) {
                    $startDate = \Illuminate\Support\Carbon::parse($startInput)->startOfDay();
                    $endDate = \Illuminate\Support\Carbon::parse($endInput)->endOfDay();
                } else {
                    $startDate = \Illuminate\Support\Carbon::now()->subDays(6)->startOfDay();
                }
            }

            // 2. ตั้งชื่อไฟล์ Excel
            $fileName = 'Analytics_Report_' . \Illuminate\Support\Carbon::now()->format('Ymd_His') . '.xlsx';

            // 3. สั่งให้ไลบรารี Maatwebsite สร้างไฟล์และส่งกลับไปที่หน้าบ้าน
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\AnalyticsExport($profile->id, $startDate, $endDate),
                $fileName
            );

        } catch (\Throwable $e) {
            // 🚨 ดักจับ Error ขั้นเด็ดขาด และพิมพ์ลง Log แบบเน้นๆ
            \Illuminate\Support\Facades\Log::error('🚨 ตัวการ EXCEL_ERROR คือ: ' . $e->getMessage() . ' | พังที่ไฟล์: ' . $e->getFile() . ' | บรรทัดที่: ' . $e->getLine());
            
            return response()->json([
                'success' => false,
                'error_message' => 'EXCEL_ERROR: ' . $e->getMessage()
            ], 500);
        }
    }

}