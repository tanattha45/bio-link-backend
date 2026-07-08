<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; // Request การรับข้อมูลจาก API 
use App\Models\User; // Model ของตาราง users
use Carbon\Carbon; // จัดการวันเวลา
use Illuminate\Support\Facades\DB; // คิวรี่ดาต้าเบสโดยตรง
use Illuminate\Support\Facades\Mail; 

use App\Mail\InactiveUserReminder;   
use App\Mail\ActiveTrafficReminder;
use App\Mail\NoLinkReminder;   


class AdminDashboardController extends Controller
{

    // 1. ฟังก์ชันส่งรายคน 
    public function sendReminderSingle($id)
    {
        $user = User::findOrFail($id);
        
        // เรียกใช้ฟังก์ชันตัวช่วยด้านล่าง เพื่อส่งอีเมลตามเงื่อนไข
        $this->determineAndSendEmail($user);
        
        return response()->json(['message' => 'ส่งอีเมลแจ้งเตือนสำเร็จ']);
    }

    // 2. ฟังก์ชันส่งทั้งหมด 
    public function sendReminderBulk(Request $request)
    {
        // รับ array ของ user_ids จากหน้า Frontend ที่เราออกแบบไว้
        $userIds = $request->input('user_ids', []);

        if (empty($userIds)) {
            return response()->json(['status' => 'error', 'message' => 'ไม่มีผู้ใช้ที่เลือก'], 400);
        }

        $users = User::whereIn('id', $userIds)->get();
        
        foreach ($users as $user) {
            // เรียกใช้ฟังก์ชันตัวช่วยด้านล่าง
            $this->determineAndSendEmail($user);
        }

        return response()->json(['message' => 'ส่งอีเมลกลุ่มสำเร็จ']);
    }


    // สำหรับแยกกลุ่มส่งอีเมล
    private function determineAndSendEmail(User $user)
    {
        try {
            // 1. เช็คจำนวนลิงก์ (Blocks)
            $linksCount = DB::table('blocks')
                ->join('profiles', 'profiles.id', '=', 'blocks.profile_id')
                ->where('profiles.user_id', $user->id)
                ->count();

            if ($linksCount === 0) {
                // กลุ่ม 1: สมัครแล้วยังไม่ตั้งค่า (เอาคอมเมนต์ออกเมื่อสร้างไฟล์ Mail แล้ว)
                Mail::to($user->email)->queue(new NoLinkReminder($user));
            } else {
                // 2. เช็ค Traffic ล่าสุด
                $lastTrafficDate = DB::table('analytics')
                    ->join('profiles', 'profiles.id', '=', 'analytics.profile_id')
                    ->where('profiles.user_id', $user->id)
                    ->max('analytics.created_at');

                $hasRecentTraffic = $lastTrafficDate && Carbon::parse($lastTrafficDate)->greaterThanOrEqualTo(now()->subDays(7));

                if ($hasRecentTraffic) {
                    // กลุ่ม 2: ลิงก์ยังทำงานอยู่ (มี Traffic ใน 7 วัน)
                    Mail::to($user->email)->queue(new ActiveTrafficReminder($user));
                } else {
                    // กลุ่ม 3: ไม่มีความเคลื่อนไหวเลย
                    Mail::to($user->email)->queue(new InactiveUserReminder($user));
                }
            }
        } catch (\Exception $e) {
            // หากส่งอีเมลล้มเหลว (เช่น อีเมลปลอม) ให้ข้ามไปคนต่อไป
            \Illuminate\Support\Facades\Log::error("ส่งอีเมลพลาด User ID {$user->id}: " . $e->getMessage());
        }
    }
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

            $minDays = $request->query('minDays', 30);

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

            // เรียก Private Functions ทำงานแทน แล้วประกอบร่างส่งกลับ
            return response()->json([
                'status' => 'success',
                'data' => array_merge(
                    $this->getStatCardsData($start, $end, $prevStart, $prevEnd), // สถิติ 6 กล่อง
                    [
                        'chartData' => $this->getTrafficChartData($start, $end, $diffInDays), // ส่งข้อมูลกราฟ
                        'topPages'  => $this->getTopPagesData($start, $end, $prevStart, $prevEnd), // ข้อมูลจัดอันดับเพจ
                        'inactiveUsers' => $this->getInactiveUsersData($minDays)
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
        $currentAllSignups = User::where('role', '!=', 'admin')
            ->whereBetween('created_at', [$start, $end])
            ->count();
            
        $currentVerifiedSignups = User::where('role', '!=', 'admin')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('email_verified_at')
            ->count();
            
        $prevAllSignups = User::where('role', '!=', 'admin')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->count();

        // 4. ดึงข้อมูล "สมาชิกทั้งหมด" (ทั้งหมด VS ยืนยันแล้ว)
        $currentTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<=', $end)
            ->count();
            
        $currentVerifiedTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<=', $end)
            ->whereNotNull('email_verified_at')
            ->count();
            
        $prevTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<=', $prevEnd)
            ->count();

        // 5. ดึงข้อมูล "บล็อกทั้งหมด"
        // JSON_LENGTH เป็นตัวถามใน content data ว่าในนั้นมีอยู่กี่รายการ
        //  DB::table ให้ไปดูในตาราง blocks 
        // ถ้าเราใช้ count จะนับเป้นแถวแต่เราต้องการนับลึกลงไปอีกจึงใช้ sum(JSON_LENGTH(...)) แทน
        // $prevStart, $prevEnd = ใช้ดึงข้อมูลย้อนหลังมาเทียบ เพื่อคำนวณ % เพิ่มขึ้นหรือลดลง
        // $start, $end = ใช้ดึงข้อมูลที่จะแสดงให้ Admin เห็น
        $currentBlocks = DB::table('blocks')
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('COALESCE(JSON_LENGTH(content_data), 0)')) ?? 0;

        $prevBlocks = DB::table('blocks')
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum(DB::raw('COALESCE(JSON_LENGTH(content_data), 0)')) ?? 0;

        // 6. ดึงข้อมูล "ยอดคลิกรวม" (ไม่นับ Save Contact)
        // เอาคอลัมน์ block_id มาใช้โดยค่าในคอลัมน์นี้ต้อง ไม่เป็น NULL เพราะ คือการเข้าชมโปนไฟล์ไม่ใช่การคลิ๊ก
        // block_id', '!=', 999999 คือ เลือกเฉพาะข้อมูลที่ block_id ไม่เท่ากับ 999999 เพราะ มันคือ save contact
        
        // 💡 [จุดแก้ไขสำหรับคำนวณยอดคลิกแบบ In-Memory]:
        // ดึงข้อมูลออกมาล้างโครงสร้าง URL และคัดกรองลิงก์ย่อยที่อยู่ในอาเรย์ JSON ผ่าน Collection แทนคำสั่งฐานข้อมูล SQL
        // เพื่อลบลิงก์ที่ถูกผู้ใช้ลบออกไปแล้ว และไม่ให้ติดบั๊กเครื่องหมายสแลชจนกลายเป็น 0 หรือ Error 500 คอลัมน์ blocks.url ครับ
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        $rawCurrentClicks = DB::table('analytics')
            ->join('blocks', 'analytics.block_id', '=', 'blocks.id')
            ->whereNotNull('analytics.block_id')
            ->where('analytics.block_id', '!=', 999999)
            ->whereBetween('analytics.created_at', [$start, $end])
            ->get(['analytics.clicked_url', 'blocks.content_data']);

        $currentClicks = $rawCurrentClicks->filter(function($click) use ($cleanUrl) {
            $contentData = is_string($click->content_data) ? json_decode($click->content_data, true) : $click->content_data;
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) return true;
                }
            }
            return false;
        })->count();
            
        $rawPrevClicks = DB::table('analytics')
            ->join('blocks', 'analytics.block_id', '=', 'blocks.id')
            ->whereNotNull('analytics.block_id')
            ->where('analytics.block_id', '!=', 999999)
            ->whereBetween('analytics.created_at', [$prevStart, $prevEnd])
            ->get(['analytics.clicked_url', 'blocks.content_data']);

        $prevClicks = $rawPrevClicks->filter(function($click) use ($cleanUrl) {
            $contentData = is_string($click->content_data) ? json_decode($click->content_data, true) : $click->content_data;
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) return true;
                }
            }
            return false;
        })->count();

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

        // 💡 [จุดแก้ไขสำหรับคำนวณยอดคลิกฝั่งกราฟรายวัน]:
        // ล้างรูปแบบคิวรี่ตาราง blocks.url ออกเพื่อปิดบั๊ก Error 500 และจับกลุ่มตัวเลขสถิติให้ถูกต้องตรงกับการ์ดรวมด้านบนครับ
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        $rawChartClicks = DB::table('analytics')
            ->join('blocks', 'analytics.block_id', '=', 'blocks.id')
            ->whereBetween('analytics.created_at', [$start, $end])
            ->whereNotNull('analytics.block_id')
            ->where('analytics.block_id', '!=', 999999)
            ->get(['analytics.created_at', 'analytics.clicked_url', 'blocks.content_data']);

        $filteredChartClicks = $rawChartClicks->filter(function($click) use ($cleanUrl) {
            $contentData = is_string($click->content_data) ? json_decode($click->content_data, true) : $click->content_data;
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) return true;
                }
            }
            return false;
        });

        $clicksDataGrouped = $filteredChartClicks->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));

        $dailySaves = DB::table('analytics')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])->where('block_id', 999999)
            ->groupBy('date')->pluck('count', 'date');

        // ดึงข้อมูลยอดสมัครรายวัน (เพิ่มเงื่อนไขตัด admin ออก)
        $dailySignups = User::where('role', '!=', 'admin')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')->pluck('count', 'date');

        $dailyBlocks = DB::table('blocks')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(JSON_LENGTH(content_data)) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')->pluck('count', 'date');

        // 7.2 คำนวณฐานของ User เดิมที่มีอยู่ก่อนเริ่มวันที่ startDate (เพื่อทำกราฟสมาชิกสะสม)
        $baseTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<', $start)
            ->count();
            
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

            $clicksCount = $clicksDataGrouped->has($dateStr) ? $clicksDataGrouped->get($dateStr)->count() : 0;

            $chartData[] = [
                'name' => $formattedDate, 
                'views' => isset($dailyViews[$dateStr]) ? (int)$dailyViews[$dateStr] : 0,
                'clicks' => $clicksCount,
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
                
                // 1. Views รอบปัจจุบัน
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as views"),
                
                // 2. Views รอบก่อนหน้า
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$prevStart}' AND '{$prevEnd}' THEN 1 END) as prev_views"),
                
                // 3. Clicks รอบปัจจุบัน (อันนี้คือยอดดิบ เราจะดึงมาเผื่อเรียงลำดับคร่าวๆ ก่อน)
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as raw_clicks"),
                
                // 4. Saves รอบปัจจุบัน
                DB::raw("COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) as saves")
            )
            ->whereBetween('analytics.created_at', [$prevStart, $end])
            ->groupBy('profiles.id', 'profiles.username')
            
            // เรียงลำดับจากคะแนน Performance Score แบบคร่าวๆ จากฐานข้อมูลก่อนดึงมา 5 อันดับ
            ->orderByRaw("(
                (COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 1) +
                (COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 5) +
                (COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$start}' AND '{$end}' THEN 1 END) * 15)
            ) DESC")
            ->limit(5)
            ->get()
            ->map(function($page) use ($start, $end) {
                

                // ระบบค้นหา Popular Link (เจาะลึกถึงลิงก์ย่อย)
                // 1. ดึงรายการคลิกทั้งหมดของโปรไฟล์นี้ (เพื่อเอา clicked_url มาเทียบ)
                $allClicks = DB::table('analytics')
                    ->where('profile_id', $page->id)
                    ->whereNotNull('block_id')
                    ->where('block_id', '!=', 999999)
                    ->whereBetween('created_at', [$start, $end])
                    ->get();

                // 2. ฟังก์ชันทำความสะอาด URL (เหมือนใน AnalyticsController)
                $cleanUrl = function($u) {
                    if (empty($u)) return '';
                    $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
                    $u = preg_replace('#^www\.#', '', $u);
                    return strtolower(trim($u));
                };

                $popularTitle = 'ยังไม่มีข้อมูลคลิก';
                $popularClicks = 0;
                $accurateClicks = 0; // 💡 สร้างตัวแปรใหม่เพื่อเก็บ "ยอดคลิกที่แท้จริง" (ตัดลิงก์ที่โดนลบออกแล้ว)

                if ($allClicks->count() > 0) {
                    $blocks = DB::table('blocks')->where('profile_id', $page->id)->get();
                    $linksPerformance = collect();

                    foreach ($blocks as $block) {
                        $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;

                        if (is_array($contentData)) {
                            foreach ($contentData as $item) {
                                $url = trim($item['url'] ?? $item['link'] ?? '');
                                if (empty($url)) continue;

                                // 🎯 นับคลิกโดยเช็ค block_id และเทียบ URL ที่ทำความสะอาดแล้ว
                                $clicksCount = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                                    return (string)$click->block_id === (string)$block->id && 
                                           $cleanUrl($click->clicked_url) === $cleanUrl($url);
                                })->count();

                                $itemIcon = $item['iconId'] ?? $item['icon'] ?? $block->icon ?? 'Link';

                                $linksPerformance->push([
                                    'title' => $item['name'] ?? $item['title'] ?? $block->title ?? 'ไม่มีชื่อลิงก์',
                                    'clicks' => $clicksCount,
                                    'icon' => $itemIcon,
                                    'type' => $block->type ?? ''
                                ]);
                            }
                        } else {
                            // 💡 เผื่อกรณีที่เป็นลิงก์เดี่ยว ไม่ใช่ Array
                            $url = trim($block->url ?? '');
                            if (!empty($url)) {
                                $clicksCount = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                                    return (string)$click->block_id === (string)$block->id && 
                                           $cleanUrl($click->clicked_url) === $cleanUrl($url);
                                })->count();

                                $linksPerformance->push([
                                    'title' => $block->title ?? 'ไม่มีชื่อลิงก์',
                                    'clicks' => $clicksCount,
                                    'icon' => $block->icon ?? 'Link',
                                    'type' => $block->type ?? ''
                                ]);
                            }
                        }
                    }

                    // 💡 คำนวณยอดคลิกรวมที่แม่นยำ (บวกเฉพาะคลิกของลิงก์ที่ยังหลงเหลืออยู่)
                    $accurateClicks = $linksPerformance->sum('clicks');

                    // กรอง YouTube / TikTok และบล็อกประเภท VIDEO ออก (ตามเงื่อนไขในไฟล์แยกของคุณ)
                    $filteredLinks = $linksPerformance->filter(function($link) {
                        $iconStr = strtolower($link['icon'] ?? '');
                        $typeStr = strtolower($link['type'] ?? '');
                        return !($iconStr === 'youtube' || $iconStr === 'tiktok' || $typeStr === 'video');
                    });

                    // จัดอันดับลิงก์ที่ถูกคลิกเยอะที่สุดมา 1 อัน
                    $topLink = $filteredLinks->sortByDesc('clicks')->first();
                    
                    if ($topLink && $topLink['clicks'] > 0) {
                        $popularTitle = $topLink['title'];
                        $popularClicks = $topLink['clicks'];
                    }
                }


                // คำนวณสถิติและสร้างผลลัพธ์
                $views = (int)$page->views;
                $clicks = $accurateClicks; // 💡 แทนที่ยอดดิบ ($page->raw_clicks) ด้วยยอดคลิกที่คำนวณใหม่
                $saves = (int)$page->saves;
                $prevViews = (int)$page->prev_views;

                // คำนวณ Growth
                $growth = 0;
                if ($prevViews == 0 && $views > 0) $growth = 100;
                elseif ($prevViews > 0) $growth = round((($views - $prevViews) / $prevViews) * 100, 1);

                // คำนวณ CTR
                $ctr = $views > 0 ? round((($clicks+$saves) / $views) * 100, 1) : 0.0;

                // คำนวณ Performance Score ใหม่ ให้สัมพันธ์กับยอดคลิกใหม่
                $baseScore = ($views * 1) + ($clicks * 5) + ($saves * 15);
                $growthBonus = min($growth, 50) * 0.01; 
                $finalScore = round($baseScore * (1 + $growthBonus), 2);

                return [
                    'id' => $page->id,
                    'name' => '@' . $page->name, 
                    'link' => 'bio.link/' . $page->link,
                    'views' => $views,
                    'clicks' => $clicks, // 💡 ยอดนี้จะแสดงผลถูกต้องตามลิงก์ที่มีอยู่จริง
                    'saves' => $saves, 
                    'ctr' => $ctr,
                    'score' => $finalScore,
                    'growth' => $growth,
                    'popular_link' => [
                        'title' => $popularTitle,
                        'clicks' => $popularClicks
                    ]
                ];
            })
            // 💡 สั่งเรียงลำดับ Array ด้วยคะแนนที่คำนวณใหม่อีกครั้งให้แม่นยำ 100%
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }

    // คำนวณเปอร์เซ็นต์ 
    private function calculateTrend($curr, $prev) 
    {
        // 8. คำนวณเปอร์เซ็นต์
        if ($prev == 0 && $curr == 0) return 0;
        if ($prev == 0) return 100;
        return round((($curr - $prev) / $prev) * 100, 1);
    }

    // Inactive Table
    private function getInactiveUsersData($minDays = 7)
    {
        $thresholdDate = now()->subDays((int)$minDays);
        $thresholdTimestamp = $thresholdDate->timestamp;

        $users = User::select('users.id', 'users.display_name', 'users.username', 'users.created_at')
            ->where('users.role', 'user') 
            ->selectSub(function ($query) {
                $query->selectRaw('MAX(last_activity)')
                      ->from('sessions')
                      ->whereColumn('sessions.user_id', 'users.id');
            }, 'last_active_ts')
            ->selectSub(function ($query) {
                $query->selectRaw('COALESCE(SUM(JSON_LENGTH(blocks.content_data)), 0)')
                      ->from('blocks')
                      ->join('profiles', 'profiles.id', '=', 'blocks.profile_id')
                      ->whereColumn('profiles.user_id', 'users.id');
            }, 'total_links_count')
            
            // 🛠️ 1. เพิ่ม Subquery เพื่อดึงเวลาแก้ไขบล็อกล่าสุด (MAX updated_at จากตาราง blocks)
            ->selectSub(function ($query) {
                $query->selectRaw('MAX(blocks.updated_at)') // ใช้ updated_at เพื่อดูเวลาที่แก้ไขบล็อกครั้งสุดท้าย
                      ->from('blocks')
                      ->join('profiles', 'profiles.id', '=', 'blocks.profile_id')
                      ->whereColumn('profiles.user_id', 'users.id');
            }, 'last_block_updated_at')
            
            ->selectSub(function ($query) {
                $query->selectRaw('MAX(analytics.created_at)')
                      ->from('analytics')
                      ->join('profiles', 'profiles.id', '=', 'analytics.profile_id') 
                      ->whereColumn('profiles.user_id', 'users.id');
            }, 'last_link_activity_at')
            ->get();

        return $users->filter(function ($user) use ($thresholdTimestamp, $thresholdDate) {
            
            // 1. เช็กความเคลื่อนไหวฝั่ง "การล็อกอิน (Session) หรือ วันสมัคร"
            $isLoginInactive = false;
            if ($user->last_active_ts) {
                // ล็อกอินล่าสุดเก่ากว่า $threshold (เช่น 7 วัน) ใช่หรือไม่
                $isLoginInactive = $user->last_active_ts < $thresholdTimestamp; 
            } else {
                // ถ้าไม่มี Session เลย ให้ดูวันที่สมัครแทน
                $createdAt = Carbon::parse($user->created_at);
                $isLoginInactive = $createdAt->lessThan($thresholdDate);
            }

            // 2. เช็กความเคลื่อนไหวฝั่ง "การแก้ไขบล็อกล่าสุด"
            $isBlockInactive = true; // ตั้งต้นให้ true ไว้ก่อน กรณีที่ไม่มีบล็อกเลย
            if ($user->last_block_updated_at) {
                $blockUpdatedAt = Carbon::parse($user->last_block_updated_at);
                // แก้บล็อกล่าสุดเก่ากว่า $threshold ใช่หรือไม่
                $isBlockInactive = $blockUpdatedAt->lessThan($thresholdDate);
            }

            // บัญชีนี้จะถูกส่งไปแสดงผล (ถือว่า Inactive จริงๆ) ก็ต่อเมื่อ...
            // "ไม่ได้ล็อกอินนานกว่ากำหนด" AND "ไม่ได้แก้บล็อกนานกว่ากำหนด" ทั้งคู่
            return $isLoginInactive && $isBlockInactive;
            
        })->map(function ($user) use ($minDays) { 
            
            // 2. จัดการข้อมูลสำหรับคอลัมน์ "แก้ไขบล็อกล่าสุด"
            // 2. จัดการข้อมูลสำหรับคอลัมน์ "แก้ไขบล็อกล่าสุด"
            if ($user->last_block_updated_at) {
                $lastBlockUpdate = Carbon::parse($user->last_block_updated_at);
                $dateDisplay = $lastBlockUpdate->translatedFormat('d M Y');
                $daysInactive = (int) $lastBlockUpdate->diffInDays(now());
                $daysDisplay = $daysInactive . ' วันที่แล้ว';
            } else {
                $dateDisplay = 'ไม่มีข้อมูล';
                $daysDisplay = '-';
            }

            $lastLinkActivity = $user->last_link_activity_at ? Carbon::parse($user->last_link_activity_at) : null;

            // เช็กสถานะ 
            $status = 'ไม่มีความเคลื่อนไหว';
            $statusColor = 'bg-red-500';
            
            if ((int)$user->total_links_count === 0) {
                $status = 'สมัครแล้วยังไม่ตั้งค่า';
                $statusColor = 'bg-yellow-500';
            
            } else if ($lastLinkActivity && $lastLinkActivity->greaterThanOrEqualTo(now()->subDays(7))) {
                $status = 'มีผู้เข้าชม (ไม่มีการอัพเดทบล็อก)';
                $statusColor = 'bg-blue-500'; 
                
            } else {
                $status = 'ไม่มีผู้เข้าชม และ ไม่มีการอัพเดทบัญชี';
                $statusColor = 'bg-red-500';
            }

            // 🛠️ เพิ่มการประกาศตัวแปรตรงนี้ ก่อน return!
            $lastActiveMoment = $user->last_active_ts 
                ? Carbon::createFromTimestamp($user->last_active_ts) 
                : Carbon::parse($user->created_at);

            return [
                'id' => $user->id,
                'name' => $user->display_name,    
                'handle' => '@' . $user->username, 
                'links' => (int)$user->total_links_count,
                
                'date' => $dateDisplay, 
                'daysAgo' => $daysDisplay,

                // ตอนนี้ระบบจะรู้จัก $lastActiveMoment แล้วครับ
                'lastLoginDate' => $lastActiveMoment->translatedFormat('d M Y'),
                'lastLoginDaysAgo' => (int) $lastActiveMoment->diffInDays(now()) . ' วันที่แล้ว',
                
                'lastLinkActivity' => $lastLinkActivity ? $lastLinkActivity->translatedFormat('d M Y') : 'ไม่มีข้อมูลการใช้งาน',
                'hasTraffic' => $status === 'มีผู้เข้าชม (ไม่มีการอัพเดทบล็อก)', 
                'status' => $status,
                'statusColor' => $statusColor,
            ];
            
        })->values();
    }
}