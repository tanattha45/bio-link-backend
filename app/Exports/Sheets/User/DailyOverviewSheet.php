<?php

namespace App\Exports\Sheets\User;

use App\Models\Analytic;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize; 

class DailyOverviewSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $profileId, $startDate, $endDate;

    public function __construct($profileId, $startDate, $endDate)
    {
        $this->profileId = $profileId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function array(): array
    {
        $days = $this->startDate->diffInDays($this->endDate) + 1;
        $data = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $this->startDate->copy()->addDays($i)->format('Y-m-d');
            
            $views = Analytic::where('profile_id', $this->profileId)->whereNull('block_id')->whereDate('created_at', $date)->count();
            $clicks = Analytic::where('profile_id', $this->profileId)->whereNotNull('block_id')->where('block_id', '!=', 999999)->whereDate('created_at', $date)->count();
            $saves = Analytic::where('profile_id', $this->profileId)->where('block_id', 999999)->whereDate('created_at', $date)->count();
            
            $ctr = $views > 0 ? round(($clicks / $views) * 100, 1) . '%' : '0%';

            // 🌟 แก้ไข: บังคับให้ค่า 0 เป็นข้อความ (String) เพื่อป้องกัน Excel แปลงเป็นช่องว่าง
            $data[] = [
                $date, 
                $views == 0 ? '0' : $views, 
                $clicks == 0 ? '0' : $clicks, 
                $saves == 0 ? '0' : $saves, 
                $ctr
            ];
        }

        return $data;
    }

    public function headings(): array { return ['วันที่ (Date)', 'ยอดเข้าชมโปรไฟล์ (Views)', 'ยอดคลิกลิงก์รวม (Total Clicks)', 'ยอดเซฟคอนแทค (Save Contacts)', 'CTR (%)']; }
    public function title(): string { return 'ภาพรวมสถิติรายวัน'; }
}