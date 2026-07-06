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

class InactiveAccountsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison,WithEvents
{
    protected $start;
    protected $end;

    // ถึงแม้หน้านี้จะเป็น Snapshot ของปัจจุบัน แต่เรารับ $start, $end ไว้เผื่อใช้อ้างอิง
    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public function collection()
    {
        $today = Carbon::today();

        // 1. ดึงข้อมูล User ควงคู่มากับ Profile
        $accounts = DB::table('users')
            ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->select(
                'users.id as user_id',
                'users.display_name',
                'users.username',
                'users.updated_at as user_updated_at',
                'profiles.id as profile_id',
                'profiles.updated_at as profile_updated_at'
            )
            ->where('users.role', 'user') // ซ่อน Super Admin จากรายงาน
            ->get();

        $data = $accounts->map(function ($account) use ($today) {
            
            // 2. หาเวลาที่อัพเดทล่าสุด (เทียบว่า Profile หรือ User อันไหนอัพเดทล่าสุด)
            $updatedAt = $account->profile_updated_at ?? $account->user_updated_at;
            $lastUpdated = Carbon::parse($updatedAt);
            $daysAgo = (int)$lastUpdated->diffInDays($today);

            // 3. นับจำนวนบล็อกใน Bio
            $blockCount = 0;
            if ($account->profile_id) {
                $blockCount = DB::table('blocks')->where('profile_id', $account->profile_id)->count();
            }

            // 4. หาการใช้งานลิงก์ล่าสุด (อิงจากตาราง analytics)
            $lastUsage = null;
            if ($account->profile_id) {
                $lastUsage = DB::table('analytics')
                    ->where('profile_id', $account->profile_id)
                    ->max('created_at');
            }

            $lastUsageText = 'ไม่มีข้อมูลการใช้งาน';
            if ($lastUsage) {
                $lastUsageText = Carbon::parse($lastUsage)->format('d M Y');
            }

            // 5. กำหนดสถานะ (ตาม Logic ในภาพของคุณเป๊ะๆ)
            if ($blockCount == 0) {
                $status = 'สมัครแล้วยังไม่ตั้งค่า';
            } elseif ($blockCount > 0 && !$lastUsage) {
                $status = 'ไม่มีความเคลื่อนไหว';
            } else {
                $status = 'ลิงก์ยังทำงานอยู่';
            }

            return [
                'display_name' => $account->display_name ?? $account->username ?? 'ไม่มีชื่อ',
                'username'     => '@' . $account->username,
                'blocks'       => $blockCount . ' บล็อก',
                'last_update'  => $lastUpdated->format('d M Y'),
                'days_ago'     => $daysAgo . ' วันที่แล้ว',
                'last_usage'   => $lastUsageText,
                'status'       => $status,
                '_raw_days'    => $daysAgo // เก็บค่าจำนวนวันดิบไว้ใช้จัดเรียง
            ];
        });

        // 6. จัดเรียงคนที่หายไปนานที่สุดขึ้นบน แล้วตัดคอลัมน์ช่วยเรียง (_raw_days) ทิ้งไป
        return $data->sortByDesc('_raw_days')->map(function($item) {
            unset($item['_raw_days']);
            return $item;
        });
    }

    public function headings(): array
    {
        return [
            'ชื่อบัญชี (Display Name)',
            'USERNAME',
            'บล็อกใน BIO',
            'อัพเดทล่าสุด',
            'ไม่ได้อัพเดทมาแล้ว',
            'การใช้งานลิงก์ล่าสุด',
            'สถานะ'
        ];
    }

    public function title(): string
    {
        return 'Inactive Accounts';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getTabColor()->setRGB('F59E0B'); 
            },
        ];
    }
}