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

class InactiveAccountsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison, WithEvents
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public function collection()
    {
        $today = Carbon::today();

        // 1. ดึงข้อมูล User พร้อม Subquery หาจำนวนบล็อก, เวลาแก้บล็อกล่าสุด, และเวลาเข้าชมล่าสุด
        $accounts = DB::table('users')
            ->select(
                'users.id as user_id',
                'users.display_name',
                'users.username',
                'users.created_at'
            )
            ->selectSub(function ($query) {
                $query->selectRaw('COALESCE(SUM(JSON_LENGTH(blocks.content_data)), 0)')
                      ->from('blocks')
                      ->join('profiles', 'profiles.id', '=', 'blocks.profile_id')
                      ->whereColumn('profiles.user_id', 'users.id');
            }, 'total_links_count')
            ->selectSub(function ($query) {
                // เปลี่ยนมาใช้เวลาอัปเดตของตาราง blocks แทน profile/user
                $query->selectRaw('MAX(blocks.updated_at)')
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
            ->where('users.role', 'user') // ซ่อน Super Admin
            ->get();

        $data = $accounts->map(function ($account) use ($today) {
            
            // 2. จำนวนบล็อก
            $blockCount = (int) $account->total_links_count;

            // 3. จัดการเวลา "แก้ไขบล็อกล่าสุด"
            if ($account->last_block_updated_at) {
                $lastBlockUpdate = Carbon::parse($account->last_block_updated_at);
                $lastUpdateText = $lastBlockUpdate->format('d M Y');
                $daysAgo = (int) $lastBlockUpdate->diffInDays($today);
                $daysAgoText = $daysAgo . ' วันที่แล้ว';
            } else {
                $lastUpdateText = 'ไม่มีข้อมูล';
                // ถ้าไม่มีบล็อก ให้ใช้วันสมัครเป็นเกณฑ์ในการคำนวณวันดิบสำหรับจัดเรียง
                $daysAgo = (int) Carbon::parse($account->created_at)->diffInDays($today); 
                $daysAgoText = '-';
            }

            // 4. จัดการเวลา "เข้าชมล่าสุด"
            $lastLinkActivity = $account->last_link_activity_at ? Carbon::parse($account->last_link_activity_at) : null;
            $lastUsageText = $lastLinkActivity ? $lastLinkActivity->format('d M Y') : 'ไม่มีข้อมูลการใช้งาน';

            // 5. กำหนดสถานะ (ตาม Logic 3 เคสล่าสุดแบบเป๊ะๆ)
            if ($blockCount === 0) {
                $status = 'สมัครแล้วยังไม่ตั้งค่า';
            } elseif ($lastLinkActivity && $lastLinkActivity->greaterThanOrEqualTo(now()->subDays(7))) {
                // มีบล็อก และมีคนดูภายใน 7 วัน
                $status = 'มีผู้เข้าชม (ไม่มีการอัพเดทบล็อก)';
            } else {
                // มีบล็อก แต่ไม่มีใครดูเลยเกิน 7 วัน
                $status = 'ไม่มีผู้เข้าชม และ ไม่มีการอัพเดทบัญชี';
            }

            return [
                'display_name' => $account->display_name ?? $account->username ?? 'ไม่มีชื่อ',
                'blocks'       => $blockCount . ' บล็อก',
                'last_update'  => $lastUpdateText,
                'days_ago'     => $daysAgoText,
                'last_usage'   => $lastUsageText,
                'status'       => $status,
                '_raw_days'    => $daysAgo // เก็บค่าจำนวนวันดิบไว้ใช้จัดเรียง
            ];
        });

        // 6. จัดเรียงคนที่หายไปนานที่สุดขึ้นบน แล้วตัดคอลัมน์ช่วยเรียงทิ้ง
        return $data->sortByDesc('_raw_days')->map(function($item) {
            unset($item['_raw_days']);
            return $item;
        });
    }

    public function headings(): array
    {
        // 7. เปลี่ยนชื่อหัวคอลัมน์ให้ตรงกับหน้าเว็บเป๊ะๆ
        return [
            'ชื่อบัญชี (Display Name)',
            'จำนวนบล็อก',
            'แก้ไขบล็อกล่าสุด', 
            'ไม่ได้แก้ไขมาแล้ว', 
            'ผู้เข้าชมล่าสุด', 
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