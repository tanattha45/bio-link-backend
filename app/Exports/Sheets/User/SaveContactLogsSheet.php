<?php

namespace App\Exports\Sheets\User;

use App\Models\Analytic;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize; 

class SaveContactLogsSheet implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $profileId, $startDate, $endDate;

    public function __construct($profileId, $startDate, $endDate)
    {
        $this->profileId = $profileId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        return Analytic::where('profile_id', $this->profileId)
            ->where('block_id', 999999)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function map($analytic): array
    {
        return [
            $analytic->created_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s'),
            $analytic->referrer_url ?: '(Direct/เข้าโดยตรง)',
            $analytic->user_agent
        ];
    }

    public function headings(): array { return ['วัน-เวลาที่บันทึก', 'แหล่งที่มา (Referrer)', 'อุปกรณ์ / เบราว์เซอร์']; }
    public function title(): string { return 'ประวัติการเซฟคอนแทค'; }
}