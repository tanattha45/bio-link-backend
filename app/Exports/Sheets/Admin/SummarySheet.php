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
        // 1. ดึงข้อมูลดิบรายวัน (ใช้โค้ดเดิมของคุณทั้งหมด)
        $dailyViews = DB::table('analytics')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNull('block_id')
            ->groupBy('date')->pluck('count', 'date');

        $dailyClicks = DB::table('analytics')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNotNull('block_id')->where('block_id', '!=', 999999)
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

        // 2. คำนวณฐานของ User เดิม
        $baseTotalUsers = User::where('role', '!=', 'admin')
            ->where('created_at', '<', $this->start)
            ->count();
        $runningTotalUsers = $baseTotalUsers;

        $rows = [];

        // 3. ใช้ While Loop (เช็คตามวันที่จริง จะแม่นยำกว่าการบวกเลขจำนวนวัน diffInDays)
        $currentDate = $this->start->copy();
        
        while ($currentDate->lte($this->end)) {
            $dateStr = $currentDate->format('Y-m-d');
            
            $signupsToday = isset($dailySignups[$dateStr]) ? (int)$dailySignups[$dateStr] : 0;
            $runningTotalUsers += $signupsToday;

            $rows[] = [
                'date'        => $currentDate->format('d/m/Y'),
                'signups'     => $signupsToday,
                'total_users' => $runningTotalUsers,
                'views'       => isset($dailyViews[$dateStr]) ? (int)$dailyViews[$dateStr] : 0,
                'blocks'      => isset($dailyBlocks[$dateStr]) ? (int)$dailyBlocks[$dateStr] : 0,
                'clicks'      => isset($dailyClicks[$dateStr]) ? (int)$dailyClicks[$dateStr] : 0,
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