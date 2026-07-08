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
        $this->start = Carbon::parse($start)->startOfDay();
        $this->end = Carbon::parse($end)->endOfDay();
    }

    public function collection()
    {
        $today = Carbon::today();
        
        $thresholdDate = $this->start;
        $thresholdTimestamp = $thresholdDate->timestamp;

        $accounts = DB::table('users')
            ->select(
                'users.id as user_id',
                'users.display_name',
                'users.username',
                'users.created_at'
            )
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
            ->selectSub(function ($query) {
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
            ->where('users.role', 'user')
            ->get();

        $filteredAccounts = $accounts->filter(function ($account) use ($thresholdTimestamp, $thresholdDate) {
            
            $isLoginInactive = false;
            if ($account->last_active_ts) {
                $isLoginInactive = $account->last_active_ts < $thresholdTimestamp; 
            } else {
                $createdAt = Carbon::parse($account->created_at);
                $isLoginInactive = $createdAt->lessThan($thresholdDate);
            }

            $isBlockInactive = true;
            if ($account->last_block_updated_at) {
                $blockUpdatedAt = Carbon::parse($account->last_block_updated_at);
                $isBlockInactive = $blockUpdatedAt->lessThan($thresholdDate);
            }

            return $isLoginInactive && $isBlockInactive;
        });

        $data = $filteredAccounts->map(function ($account) use ($today) {
            
            $blockCount = (int) $account->total_links_count;

            // 🛠️ 1. คำนวณข้อมูล ล็อกอินล่าสุด
            $lastActiveMoment = $account->last_active_ts 
                ? Carbon::createFromTimestamp($account->last_active_ts) 
                : Carbon::parse($account->created_at);
            
            $lastLoginDateText = $lastActiveMoment->format('d M Y');
            $lastLoginDaysAgo = (int) $lastActiveMoment->diffInDays($today);
            $lastLoginDaysAgoText = $lastLoginDaysAgo . ' วันที่แล้ว';

            // 2. คำนวณข้อมูล แก้ไขบล็อกล่าสุด
            if ($account->last_block_updated_at) {
                $lastBlockUpdate = Carbon::parse($account->last_block_updated_at);
                $lastUpdateText = $lastBlockUpdate->format('d M Y');
                $daysAgo = (int) $lastBlockUpdate->diffInDays($today);
                $daysAgoText = $daysAgo . ' วันที่แล้ว';
            } else {
                $lastUpdateText = 'ไม่มีข้อมูล';
                $daysAgo = (int) Carbon::parse($account->created_at)->diffInDays($today); 
                $daysAgoText = '-';
            }

            $lastLinkActivity = $account->last_link_activity_at ? Carbon::parse($account->last_link_activity_at) : null;
            $lastUsageText = $lastLinkActivity ? $lastLinkActivity->format('d M Y') : 'ไม่มีข้อมูลการใช้งาน';

            $minDays = (int) $this->start->diffInDays($today);
            if ($blockCount === 0) {
                $status = 'สมัครแล้วยังไม่ตั้งค่า';
            } elseif ($lastLinkActivity && $lastLinkActivity->greaterThanOrEqualTo(now()->subDays($minDays))) {
                $status = 'มีผู้เข้าชม (ไม่มีการอัพเดทบล็อก)';
            } else {
                $status = 'ไม่มีผู้เข้าชม และ ไม่มีการอัพเดทบัญชี';
            }

            // 🛠️ 3. เพิ่มข้อมูลล็อกอินเข้าไปใน Array โดยเรียงลำดับให้ตรงกับ Headings
            return [
                'display_name'         => $account->display_name ?? $account->username ?? 'ไม่มีชื่อ',
                'blocks'               => $blockCount . ' บล็อก',
                'last_login_date'      => $lastLoginDateText,      // คอลัมน์ใหม่
                'last_login_days_ago'  => $lastLoginDaysAgoText,   // คอลัมน์ใหม่
                'last_update'          => $lastUpdateText,
                'days_ago'             => $daysAgoText,
                'last_usage'           => $lastUsageText,
                'status'               => $status,
                '_raw_days'            => $daysAgo 
            ];
        });

        return $data->sortByDesc('_raw_days')->map(function($item) {
            unset($item['_raw_days']);
            return $item;
        });
    }

    public function headings(): array
    {
        // 🛠️ 4. เพิ่มหัวคอลัมน์ให้ตรงกับข้อมูลที่ส่งมา
        return [
            'ชื่อบัญชี (Display Name)',
            'จำนวนบล็อก',
            'ล็อกอินล่าสุด',        // หัวคอลัมน์ใหม่
            'ไม่ได้ล็อกอินมาแล้ว',    // หัวคอลัมน์ใหม่
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