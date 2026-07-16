<?php

namespace App\Exports\Sheets\Admin;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SavedActionsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison, WithEvents
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        // 💡 ปรับให้เป็น Carbon Object ทันทีเพื่อป้องกันปัญหา String Format
        $this->start = Carbon::parse($start)->startOfDay();
        $this->end = Carbon::parse($end)->endOfDay();
    }

    public function collection()
    {
        // ใช้ DB::table และเช็คเงื่อนไข block_id = 999999
        return DB::table('analytics')
            ->join('profiles', 'analytics.profile_id', '=', 'profiles.id')
            ->select(
                'analytics.created_at',
                'analytics.session_id',
                'profiles.username as owner_username',
                'analytics.user_agent'
            )
            ->where('analytics.block_id', 999999)
            // 💡 ตรวจสอบช่วงเวลาอีกครั้ง
            ->whereBetween('analytics.created_at', [$this->start->toDateTimeString(), $this->end->toDateTimeString()])
            ->orderBy('analytics.created_at', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'timestamp' => Carbon::parse($item->created_at)->format('d/m/Y H:i:s'),
                    'visitor'   => $item->session_id ?? 'Unknown Session',
                    'owner'     => $item->owner_username,
                    'device'    => $this->parseDevice($item->user_agent),
                ];
            });
    }

    private function parseDevice($ua)
    {
        if (empty($ua)) return 'Desktop';
        
        $ua = strtolower($ua);
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'android') !== false) {
            return 'Mobile (Phone)';
        } elseif (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
            return 'Tablet';
        }
        return 'Desktop/Laptop';
    }

    public function headings(): array
    {
        return ['วัน-เวลา (Timestamp)', 
                'ผู้เยี่ยมชม (Session ID)', 
                'โปรไฟล์ที่ถูกบันทึก (Owner)', 
                'อุปกรณ์ (Device)'];
    }

    public function title(): string
    {
        return 'Saved Actions Detail';
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