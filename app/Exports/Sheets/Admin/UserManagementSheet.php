<?php

namespace App\Exports\Sheets\Admin;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UserManagementSheet implements FromQuery, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public function query()
    {
        $query = User::query();

        if ($this->start && $this->end) {
            $query->whereBetween('created_at', [$this->start . ' 00:00:00', $this->end . ' 23:59:59']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'ชื่อผู้ใช้งาน',
            'ชื่อบัญชี',
            'อีเมล',
            'สิทธิ์การใช้งาน',
            'สถานะบัญชี',
            'ประเภทการสมัคร',
            'วันที่สมัคร'
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->display_name ?: 'User',
            $user->username ?? '-',
            $user->email ?? '-',
            strtoupper($user->role ?? 'user'),
            ucfirst($user->status ?? 'active'),
            $user->google_id ? 'Google' : 'Email',
            $user->created_at ? $user->created_at->format('d M Y H:i') : '-',
        ];
    }

    public function title(): string
    {
        return 'All Users';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // ตั้งสีแท็บเป็นสีม่วงตามธีมหลักของ User Management เพื่อความสวยงาม
                $event->sheet->getDelegate()->getTabColor()->setRGB('6B46FF'); 
            },
        ];
    }
}