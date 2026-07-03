<?php

namespace App\Exports\Sheets\Admin;

use App\Models\User;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class NewSignupsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison,WithEvents
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = Carbon::parse($start)->startOfDay();
        $this->end = Carbon::parse($end)->endOfDay();
    }

    public function collection()
    {
        // ดึงข้อมูลเฉพาะ role 'user' ที่สมัครในช่วงเวลาที่กำหนด (เรียงจากใหม่ไปเก่า)
        $users = User::where('role', 'user')
                     ->whereBetween('created_at', [$this->start, $this->end])
                     ->orderBy('created_at', 'asc')
                     ->get();

        // Map ข้อมูลให้ตรงกับ Headings ใหม่ตามที่คุณต้องการ
        return $users->map(function ($user) {
            
            // เช็คช่องทางการสมัครจาก google_id
            $source = !empty($user->google_id) ? 'Google' : 'Email';

            // เช็คสถานะการยืนยันตัวตนจาก email_verified_at
            $verifiedStatus = !empty($user->email_verified_at) ? 'ยืนยันแล้ว' : 'รอยืนยัน';

            // ลำดับของ Array ตรงนี้ "ต้องตรงกับ" ลำดับของ headings() ด้านล่าง
            return [
                'created_at'   => $user->created_at ? $user->created_at->format('d/m/Y H:i:s') : '-',
                'user_id'      => $user->id,
                'username'     => $user->username,
                'display_name' => $user->display_name,
                'email'        => $user->email,
                'source'       => $source,
                'verified'     => $verifiedStatus,
                'status'       => ucfirst($user->status), // ปรับตัวพิมพ์ใหญ่ตัวแรก (เช่น active -> Active)
            ];
        });
    }

    public function headings(): array
    {
        return [
            'วันที่สมัคร',
            'User ID',
            'Username',
            'ชื่อผู้ใช้งาน',
            'อีเมล',
            'ช่องทางที่สมัคร',
            'สถานะยืนยันตัวตน',
            'สถานะบัญชี'
        ];
    }

    public function title(): string
    {
        return 'New Signups Detail';
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