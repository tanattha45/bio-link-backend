<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; // Request การรับข้อมูลจาก API 
use App\Models\User; // Model ของตาราง users
use Carbon\Carbon; // จัดการวันเวลา
use Illuminate\Support\Facades\DB; // คิวรี่ดาต้าเบสโดยตรง

class AdminDashboardController extends Controller
{

    // พนักงานรับออเดอร์ (Public Function)
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

            // 🌟 เรียกใช้พ่อครัว (Private Functions) ทำงานแทน แล้วประกอบร่างส่งกลับ
            return response()->json([
                'status' => 'success',
                'data' => array_merge(
                    $this->getStatCardsData($start, $end, $prevStart, $prevEnd), // สถิติ 6 กล่อง
                    [
                        'chartData' => $this->getTrafficChartData($start, $end, $diffInDays), // ส่งข้อมูลกราฟ
                        'topPages'  => $this->getTopPagesData($start, $end, $prevStart, $prevEnd) // ข้อมูลจัดอันดับเพจ
                    ]
                )
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => 'เกิดข้อผิดพลาดในการดึงสถิติ: ' . $e->getMessage()
            ], 500);
        }
    }


    // โซนหลังครัว (Private Functions)

    // คำนวณสถิติ 6 กล่อง
    private function getStatCardsData($start, $end, $prevStart, $prevEnd)
    {
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

        return [
            'dailySignups' => [
                'count' => $currentAllSignups,
                'verified_count' => $currentVerifiedSignups, 
                'trend' => $this->calculateTrend($currentAllSignups, $prevAllSignups)
            ],
            'totalUsers' => [
                'count' => $currentTotalUsers,
                'verified_count' => $currentVerifiedTotalUsers,
                'trend' => $this->calculateTrend($currentTotalUsers, $prevTotalUsers)
            ],
            'totalBlocks' => [
                'count' => (int)$currentBlocks,
                'trend' => $this->calculateTrend($currentBlocks, $prevBlocks)
            ],
            'totalClicks' => [
                'count' => $currentClicks,
                'trend' => $this->calculateTrend($currentClicks, $prevClicks)
            ],
            'totalSaves' => [
                'count' => $currentSaves,
                'trend' => $this->calculateTrend($currentSaves, $prevSaves)
            ],
            'totalViews' => [
                'count' => $currentViews,
                'trend' => $this->calculateTrend($currentViews, $prevViews)
            ],
        ];
    }

    // เตรียมข้อมูลให้กราฟ (Traffic Chart)
    private function getTrafficChartData($start, $end, $diffInDays)
    {
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

        return $chartData;
    }

    // จัดอันดับ Top Pages
    private function getTopPagesData($start, $end, $prevStart, $prevEnd)
    {
    return DB::table('profiles')
        ->leftJoin('analytics', 'profiles.id', '=', 'analytics.profile_id')
        ->select(
            'profiles.id',
            'profiles.username as name', 
            'profiles.username as link',
            
            // 1. Views (ผู้เข้าชม) รอบปัจจุบัน
            // นับเฉพาะที่ไม่มี block_id (แปลว่าเข้ามาดูหน้าโปรไฟล์เฉยๆ)
            DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as views"),
            
            // 2. Views รอบก่อนหน้า (เอาไว้ใช้เทียบเปอร์เซ็นต์การเติบโต)
            DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$prevStart}' AND '{$prevEnd}' THEN 1 END) as prev_views"),
            
            // 3. Clicks (ยอดกดลิงก์) รอบปัจจุบัน
            // นับเฉพาะที่มี block_id และต้องไม่ใช่เลข 999999 (ปุ่มเซฟ)
            DB::raw("COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as clicks"),
            
            // 4. Saves (ยอดเซฟคอนแทค) รอบปัจจุบัน
            // นับเฉพาะ action ที่เป็นรหัส 999999
            DB::raw("COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as saves")
        )
        ->whereBetween('analytics.created_at', [$prevStart, $end])
        ->groupBy('profiles.id', 'profiles.username')
        
        // เรียงลำดับจากคะแนน "คุณภาพ" แทนยอดวิวธรรมดา 
        // ให้น้ำหนัก: View = 1 แต้ม, Click = 5 แต้ม, Save = 15 แต้ม
        ->orderByRaw("(
            (COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 1) +
            (COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 5) +
            (COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 15)
        ) DESC")
        ->limit(5)
        ->get()
        ->map(function($page) use ($start, $end) {
            
            // --- ระบบค้นหา Popular Link ของเพจนี้ ---
            $popularBlock = DB::table('analytics')
                ->select('block_id', DB::raw('COUNT(*) as click_count'))
                ->where('profile_id', $page->id)
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('block_id')
                ->orderBy('click_count', 'desc')
                ->first();

            $popularTitle = 'ยังไม่มีข้อมูลคลิก';
            $popularClicks = 0;

            if ($popularBlock) {
                $block = DB::table('blocks')->where('id', $popularBlock->block_id)->first();
                $popularTitle = ($block && $block->title) ? $block->title : 'ลิงก์ที่ไม่มีชื่อ';
                $popularClicks = (int)$popularBlock->click_count;
            }
    
            // แปลงค่าเป็นตัวเลขเพื่อนำมาคำนวณต่อ
            $views = (int)$page->views;
            $clicks = (int)$page->clicks;
            $saves = (int)$page->saves;
            $prevViews = (int)$page->prev_views;

            // 1. คำนวณ Growth (อัตราการเติบโต)
            $growth = $this->calculateTrend($views, $prevViews);

            // 2. คำนวณ CTR (Click-Through Rate) ป้องกันการหารด้วย 0
            $ctr = $views > 0 ? round(($clicks / $views) * 100, 1) : 0.0;

            // 3. คำนวณ Performance Score ขั้นสุดท้าย 
            $baseScore = ($views * 1) + ($clicks * 5) + ($saves * 15);
            // จำกัดโบนัสการเติบโตไว้สูงสุดที่ 50% เพื่อป้องกัน "กับดักตัวเลขน้อย" ที่เพจเล็กๆ โต 400% แล้วคะแนนเวอร์
            $growthBonus = min($growth, 50) * 0.01; 
            $finalScore = round($baseScore * (1 + $growthBonus), 2);

            return [
                'id' => $page->id,
                'name' => '@' . $page->name, 
                'link' => 'bio.link/' . $page->link,
                'views' => $views,
                'clicks' => $clicks,
                'saves' => $saves, 
                'ctr' => $ctr,          // ส่งค่า CTR ไปให้หน้าบ้าน
                'score' => $finalScore, // ส่งค่า Score ไปให้หน้าบ้านจัดเรียงได้เลย
                'growth' => $growth,
                'popular_link' => [
                    'title' => $popularTitle,
                    'clicks' => $popularClicks
                ]
            ];
        });
}

    // คำนวณเปอร์เซ็นต์ 
    private function calculateTrend($curr, $prev) 
    {
        // 8. คำนวณเปอร์เซ็นต์
        if ($prev == 0 && $curr == 0) return 0;
        if ($prev == 0) return 100;
        return round((($curr - $prev) / $prev) * 100, 1);
    }
}