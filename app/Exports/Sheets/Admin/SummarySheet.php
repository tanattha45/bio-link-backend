<?php

namespace App\Exports\Sheets\Admin;

use Illuminate\Support\Facades\DB;
use App\Models\User;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize; // เพิ่มตัวนี้เพื่อให้ความกว้างคอลัมน์พอดีกับตัวอักษรอัตโนมัติ
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SummarySheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison ,WithEvents
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = Carbon::parse($start)->startOfDay();
        $this->end = Carbon::parse($end)->endOfDay();
        
        // บังคับเด็ดขาด: ถ้าวันที่สิ้นสุด (end) มีค่ามากกว่า "วันนี้" ให้จับลดลงมาเท่ากับแค่ "วันนี้" 
        $today = Carbon::today()->endOfDay();
        if ($this->end->gt($today)) {
            $this->end = $today;
        }
    }

    public function collection()
    {
        // 1. ดึงข้อมูลดิบรายวัน (Views, Saves, Signups, Blocks ใช้ตัวเดิม)
        $dailyViews = DB::table('analytics')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNull('block_id')
            ->groupBy('date')->pluck('count', 'date');

        $dailySaves = DB::table('analytics')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$this->start, $this->end])
            ->where('block_id', 999999)
            ->groupBy('date')->pluck('count', 'date');

        $dailySignups = User::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('role', '!=', 'admin') 
            ->whereBetween('created_at', [$this->start, $this->end])
            ->groupBy('date')
            ->pluck('count', 'date');

        $dailyBlocks = DB::table('blocks')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(COALESCE(JSON_LENGTH(content_data), 0)) as count'))
            ->whereBetween('created_at', [$this->start, $this->end])
            ->groupBy('date')->pluck('count', 'date');

        // 🌟 2. [ปรับปรุง] ระบบทำความสะอาด URL และคำนวณยอดคลิกแบบละเอียดรายวัน (รองรับ เบอร์โทร, อีเมล, ลิงก์ย่อย)
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            
            $u = preg_replace('#^https?://mail\.google\.com/mail/\?view=cm&fs=1&to=#i', '', $u);
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            $u = preg_replace('#^mailto:#i', '', $u);
            $u = preg_replace('#^tel:#i', '', $u);
            $u = str_replace('-', '', $u);
            
            return strtolower(trim($u));
        };

        // ดึงยอดคลิกดิบพร้อมข้อมูลบล็อกที่เกิดขึ้นในช่วงเวลานั้น
        $rawClicks = DB::table('analytics')
            ->join('blocks', 'analytics.block_id', '=', 'blocks.id')
            ->whereNotNull('analytics.block_id')
            ->where('analytics.block_id', '!=', 999999)
            ->whereBetween('analytics.created_at', [$this->start, $this->end])
            ->get(['analytics.created_at', 'analytics.clicked_url', 'blocks.content_data']);

        // นำมากรองความถูกต้องของ URL และจัดกลุ่มตามรายวัน (ปรับ Timezone ให้ตรงกับระบบ)
        $filteredClicks = $rawClicks->filter(function($click) use ($cleanUrl) {
            $contentData = is_string($click->content_data) ? json_decode($click->content_data, true) : $click->content_data;
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) return true;
                }
            }
            return false;
        });

        // จัดกลุ่มข้อมูลยอดคลิกแยกตามรายวัน 'Y-m-d'
        $clicksDataGrouped = $filteredClicks->groupBy(fn($item) => Carbon::parse($item->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d'));


        // 3. คำนวณฐานของ User เดิม
        $baseTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<', $this->start)
            ->count();
        $runningTotalUsers = $baseTotalUsers;

        $rows = [];

        // 4. วนลูปสร้างข้อมูลแถวใน Excel เช็กตามวันที่จริง
        $currentDate = $this->start->copy();
        
        while ($currentDate->lte($this->end)) {
            $dateStr = $currentDate->format('Y-m-d');
            
            $signupsToday = isset($dailySignups[$dateStr]) ? (int)$dailySignups[$dateStr] : 0;
            $runningTotalUsers += $signupsToday;

            // 🌟 ดึงยอดคลิกจากข้อมูลที่ล้างแล้วประจำวันนี้ (ถ้าไม่มีให้เป็น 0)
            $clicksToday = $clicksDataGrouped->has($dateStr) ? $clicksDataGrouped->get($dateStr)->count() : 0;

            $rows[] = [
                'date'        => $currentDate->format('d/m/Y'),
                'signups'     => $signupsToday,
                'total_users' => $runningTotalUsers,
                'views'       => isset($dailyViews[$dateStr]) ? (int)$dailyViews[$dateStr] : 0,
                'blocks'      => isset($dailyBlocks[$dateStr]) ? (int)$dailyBlocks[$dateStr] : 0,
                'clicks'      => $clicksToday, // 🌟 แสดงยอดคลิกใหม่ที่นับเบอร์/เมลได้แม่นยำ 100%
                'saves'       => isset($dailySaves[$dateStr]) ? (int)$dailySaves[$dateStr] : 0,
            ];

            // ขยับตัวแปรบวกไปทีละ 1 วันจนกว่าจะถึง $this->end
            $currentDate->addDay();
        }

        return collect($rows);
    }

    // กำหนดหัวตารางของ Sheet
    public function headings(): array
    {
        return [
            'วันที่',
            'ยอดผู้สมัครใหม่',
            'สมาชิกทั้งหมด',
            'ยอดเข้าชม',
            'บล็อกที่ถูกสร้างทั้งหมด',
            'ยอดคลิกรวม',
            'ยอดกด Save'
        ];
    }

    // กำหนดชื่อ Tab ด้านล่างของ Excel
    public function title(): string
    {
        return 'Summary Overview';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getTabColor()->setRGB('6B46FF'); 
            },
        ];
    }
}