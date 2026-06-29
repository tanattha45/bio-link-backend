<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; // Request การรับข้อมูลจาก API 
use App\Models\User; // Model ของตาราง users
use Carbon\Carbon; // จัดการวันเวลา
use Illuminate\Support\Facades\DB; // คิวรี่ดาต้าเบสโดยตรง

class AdminDashboardController extends Controller
{
    // ถ้า frontend เรียก API (GET.......) laravel จะมาเข้าฟังก์ชั่นนี้
    public function getDashboardStats(Request $request)
    {
        // 1. เช็คสิทธิ์ Admin
        if (auth()->user()->role !== 'admin') {
             return response()->json(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้'], 403);
        }

        try {
            // 2. รับและจัดการวันที่
            $startDate = $request->query('startDate');
            $endDate = $request->query('endDate');

            // ตรวจสอบว่าผู้ใช้ส่งข้อมูลมาครบมั้ย ถ้าส่งมาไม่ครบจะreturn error 
            // startOfDay() และ endOfDay() ไม่งั้น ข้อมูลวันที่ 30 หลังเที่ยงคืนจะไม่ถูกนับ หลุดจาก query
            if (!$startDate || !$endDate) {
                return response()->json(['status' => 'error', 'message' => 'กรุณาระบุช่วงวันที่ให้ครบถ้วน'], 400);
            }

            // แปลง String เป็น Carbon ($startDate = "2025-06-01"; -> 2025-06-01 00:00:00 )
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            
            // Carbon นับ "ระยะห่าง" 30-1 = 29 เราเลยต้อง +1 เพื่อให้มันแสดงผลเป็น 30 วัน
            $diffInDays = $start->diffInDays($end) + 1;

            // คำนวณช่วงก่อนหน้า เราใช้ copy เพราะ carbon มันจะแก้ค่า start เราจึงต้อง copy ไว้
            $prevStart = $start->copy()->subDays($diffInDays)->startOfDay();
            $prevEnd = $start->copy()->subDays(1)->endOfDay();

            // 3. ดึงข้อมูล "ยอดผู้สมัครใหม่" (ทั้งหมด VS ยืนยันแล้ว)
            $currentAllSignups = User::whereBetween('created_at', [$start, $end])->count();
            $currentVerifiedSignups = User::whereBetween('created_at', [$start, $end])
                ->whereNotNull('email_verified_at')
                ->count();
            $prevAllSignups = User::whereBetween('created_at', [$prevStart, $prevEnd])->count();

            // 4. ดึงข้อมูล "สมาชิกทั้งหมด" (ทั้งหมด VS ยืนยันแล้ว)
            $currentTotalUsers = User::where('created_at', '<=', $end)->count();
            $currentVerifiedTotalUsers = User::where('created_at', '<=', $end)
                ->whereNotNull('email_verified_at')
                ->count();
            $prevTotalUsers = User::where('created_at', '<=', $prevEnd)->count();

            // 5. ดึงข้อมูล "บล็อกทั้งหมด"
            // JSON_LENGTH เป็นตัวถามใน content data ว่าในนั้นมีอยู่กี่รายการ
            //  DB::table ให้ไปดูในตาราง blocks 
            // ถ้าเราใช้ count จะนับเป้นแถวแต่เราต้องการนับลึกลงไปอีกจึงใช้ sum(JSON_LENGTH(...)) แทน
            // $prevStart, $prevEnd = ใช้ดึงข้อมูลย้อนหลังมาเทียบ เพื่อคำนวณ % เพิ่มขึ้นหรือลดลง
            // $start, $end = ใช้ดึงข้อมูลที่จะแสดงให้ Admin เห็น
            $currentBlocks = DB::table('blocks')
                ->whereBetween('created_at', [$start, $end])
                ->sum(DB::raw('JSON_LENGTH(content_data)')) ?? 0;

            $prevBlocks = DB::table('blocks')
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->sum(DB::raw('JSON_LENGTH(content_data)')) ?? 0;


            // 6. ดึงข้อมูล "ยอดคลิกรวม" (ไม่นับ Save Contact)
            // เอาคอลัมน์ block_id มาใช้โดยค่าในคอลัมน์นี้ต้อง ไม่เป็น NULL เพราะ คือการเข้าชมโปนไฟล์ไม่ใช่การคลิ๊ก
            // block_id', '!=', 999999 คือ เลือกเฉพาะข้อมูลที่ block_id ไม่เท่ากับ 999999 เพราะ มันคือ save contact
            $currentClicks = DB::table('analytics')
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$start, $end])
                ->count();
                
            $prevClicks = DB::table('analytics')
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->count();

            // ดึงข้อมูล "ยอดกด Save Contact" (block_id = 999999)
            $currentSaves = DB::table('analytics')
                ->where('block_id', 999999)
                ->whereBetween('created_at', [$start, $end])
                ->count();
                
            $prevSaves = DB::table('analytics')
                ->where('block_id', 999999)
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->count();

            // ดึงข้อมูล "ยอดเข้าชมโปรไฟล์รวม" (Profile Views)
            $currentViews = DB::table('analytics')
                ->whereNull('block_id') // กรองเอาเฉพาะยอดวิว
                ->whereBetween('created_at', [$start, $end])
                ->count();
                
            $prevViews = DB::table('analytics')
                ->whereNull('block_id')
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->count();


            // 7. ดึงข้อมูลสำหรับกราฟ (Traffic Chart)
            // 7.1 ดึงข้อมูลรายวันของแต่ละสถิติ (ใช้ pluck เพื่อจัดให้อยู่ในรูป ['Y-m-d' => count])
            // นับยอด View แยกตามวัน
            $dailyViews = DB::table('analytics')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$start, $end])->whereNull('block_id')
                ->groupBy('date')->pluck('count', 'date');

            $dailyClicks = DB::table('analytics')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$start, $end])->whereNotNull('block_id')->where('block_id', '!=', 999999)
                ->groupBy('date')->pluck('count', 'date');

            $dailySaves = DB::table('analytics')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$start, $end])->where('block_id', 999999)
                ->groupBy('date')->pluck('count', 'date');

            $dailySignups = User::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('date')->pluck('count', 'date');

            $dailyBlocks = DB::table('blocks')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(JSON_LENGTH(content_data)) as count'))
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('date')->pluck('count', 'date');

            // 7.2 คำนวณฐานของ User เดิมที่มีอยู่ก่อนเริ่มวันที่ startDate (เพื่อทำกราฟสมาชิกสะสม)
            $baseTotalUsers = User::where('created_at', '<', $start)->count();
            $runningTotalUsers = $baseTotalUsers;

            $thaiMonths = [
                1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
                5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
                9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
            ];

            $chartData = [];
            
            // 7.3 วนลูปจับคู่ข้อมูลใส่วันที่
            for ($i = 0; $i < $diffInDays; $i++) {
                $dateObj = $start->copy()->addDays($i);
                $dateStr = $dateObj->format('Y-m-d');
                $formattedDate = $dateObj->format('j') . ' ' . $thaiMonths[(int)$dateObj->format('n')]; 
                
                // คำนวณผู้สมัครใหม่วันนี้ เพื่อเอาไปบวกสะสมเป็นสมาชิกทั้งหมด
                $signupsToday = isset($dailySignups[$dateStr]) ? (int)$dailySignups[$dateStr] : 0;
                $runningTotalUsers += $signupsToday;

                $chartData[] = [
                    'name' => $formattedDate, 
                    'views' => isset($dailyViews[$dateStr]) ? (int)$dailyViews[$dateStr] : 0,
                    'clicks' => isset($dailyClicks[$dateStr]) ? (int)$dailyClicks[$dateStr] : 0,
                    'saves' => isset($dailySaves[$dateStr]) ? (int)$dailySaves[$dateStr] : 0,
                    'signups' => $signupsToday,
                    'total_users' => $runningTotalUsers,
                    'blocks' => isset($dailyBlocks[$dateStr]) ? (int)$dailyBlocks[$dateStr] : 0,
                ];
            }

            // 8. คำนวณเปอร์เซ็นต์และส่งกลับ
            $calculateTrend = function($curr, $prev) {
                if ($prev == 0 && $curr == 0) return 0;
                if ($prev == 0) return 100;
                return round((($curr - $prev) / $prev) * 100, 1);
            };

            return response()->json([
                'status' => 'success',
                'data' => [
                    'dailySignups' => [
                        'count' => $currentAllSignups,
                        'verified_count' => $currentVerifiedSignups, 
                        'trend' => $calculateTrend($currentAllSignups, $prevAllSignups)
                    ],
                    'totalUsers' => [
                        'count' => $currentTotalUsers,
                        'verified_count' => $currentVerifiedTotalUsers,
                        'trend' => $calculateTrend($currentTotalUsers, $prevTotalUsers)
                    ],
                    'totalBlocks' => [
                        'count' => (int)$currentBlocks,
                        'trend' => $calculateTrend($currentBlocks, $prevBlocks)
                    ],
                    'totalClicks' => [
                        'count' => $currentClicks,
                        'trend' => $calculateTrend($currentClicks, $prevClicks)
                    ],
                    'totalSaves' => [
                        'count' => $currentSaves,
                        'trend' => $calculateTrend($currentSaves, $prevSaves)
                    ],
                    'totalViews' => [
                        'count' => $currentViews,
                        'trend' => $calculateTrend($currentViews, $prevViews)
                    ],
                    'chartData' => $chartData // ส่งข้อมูลกราฟ
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => 'เกิดข้อผิดพลาดในการดึงสถิติ: ' . $e->getMessage()
            ], 500);
        }
    }
}