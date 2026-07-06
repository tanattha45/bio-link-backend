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

class TopPerformersSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison ,WithEvents
{
    protected $start;
    protected $end;
    protected $prevStart;
    protected $prevEnd;

    public function __construct($start, $end)
    {
        $this->start = Carbon::parse($start)->startOfDay();
        $this->end = Carbon::parse($end)->endOfDay();
        
        $diff = $this->start->diffInDays($this->end) + 1;
        $this->prevStart = $this->start->copy()->subDays($diff)->startOfDay();
        $this->prevEnd = $this->start->copy()->subDays(1)->endOfDay();
    }

    public function collection()
    {
        $data = DB::table('profiles')
            ->leftJoin('analytics', 'profiles.id', '=', 'analytics.profile_id')
            ->select(
                'profiles.id',
                'profiles.username',
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$this->start}' AND '{$this->end}' THEN 1 END) as views"),
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$this->prevStart}' AND '{$this->prevEnd}' THEN 1 END) as prev_views"),
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$this->start}' AND '{$this->end}' THEN 1 END) as clicks"),
                DB::raw("COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$this->start}' AND '{$this->end}' THEN 1 END) as saves")
            )
            ->whereBetween('analytics.created_at', [$this->prevStart, $this->end])
            ->groupBy('profiles.id', 'profiles.username')
            ->get();

        return $data->map(function ($page) {
            $views = (int)$page->views;
            $clicks = (int)$page->clicks;
            $saves = (int)$page->saves;
            $prevViews = (int)$page->prev_views;

            // 1. คำนวณ CTR
            $ctr = $views > 0 ? round(($clicks / $views) * 100, 1) : 0.0;
            
            // 2. คำนวณ Growth 
            $growth = 0;
            if ($prevViews == 0 && $views == 0) {
                $growth = 0;
            } elseif ($prevViews == 0) {
                $growth = 100;
            } else {
                $growth = round((($views - $prevViews) / $prevViews) * 100, 1);
            }
            $growthFormatted = ($growth > 0 ? '+' : '') . $growth . '%';

            // 3. คำนวณ Performance Score 
            $baseScore = ($views * 1) + ($clicks * 5) + ($saves * 15);
            $growthBonus = min($growth, 50) * 0.01; 
            $finalScore = round($baseScore * (1 + $growthBonus), 2);

            // 4. ระบบค้นหา Popular Link ของเพจนี้
            $popularBlock = DB::table('analytics')
                ->select('block_id', DB::raw('COUNT(*) as click_count'))
                ->where('profile_id', $page->id)
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->groupBy('block_id')
                ->orderBy('click_count', 'desc')
                ->first();

            $popularTitle = 'ยังไม่มีข้อมูลคลิก';
            $popularClicks = 0;

            if ($popularBlock) {
                $block = DB::table('blocks')->where('id', $popularBlock->block_id)->first();
                $popularTitle = 'ลิงก์ที่ไม่มีชื่อ';
                
                if ($block && $block->content_data) {
                    $linksData = json_decode($block->content_data, true);
                    if (is_array($linksData)) {
                        foreach ($linksData as $linkItem) {
                            if (isset($linkItem['id']) && $linkItem['id'] == $popularBlock->block_id) {
                                $popularTitle = $linkItem['name'] ?? $linkItem['title'] ?? 'ลิงก์ไม่มีชื่อ';
                                break;
                            }
                        }
                    }
                }
                $popularClicks = (int)$popularBlock->click_count;
            }

            // แมปข้อมูลให้ตรงกับหัวคอลัมน์ใหม่
            return [
                'Profile Name'           => '@' . $page->username,
                'Views (Current Period)' => $views,
                'Views Growth (%)'       => $growthFormatted,
                'Clicks'                 => $clicks,
                'CTR (%)'                => $ctr . '%',
                'Saves'                  => $saves,
                'Popular Link Title'     => $popularTitle,
                'Popular Link Clicks'    => $popularClicks,
                'Performance Score'      => $finalScore,
                // เก็บค่าคะแนนดิบไว้เรียงลำดับ (แต่จะไม่แสดงใน Excel เพราะเราจะตัดออกในขั้นตอนคืนค่า)
                '_raw_score'             => $finalScore 
            ];
        })->sortByDesc('_raw_score')->map(function($item) {
            // ตัดคอลัมน์ช่วยเรียงลำดับออกก่อนลงตาราง Excel
            unset($item['_raw_score']);
            return $item;
        });
    }

    public function headings(): array
    {
        return [
            'Profile Name',
            'Views (Current Period)',
            'Views Growth (%)',
            'Clicks',
            'CTR (%)',
            'Saves',
            'Popular Link Title',
            'Popular Link Clicks',
            'Performance Score'
        ];
    }

    public function title(): string
    {
        return 'Top Performers';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getTabColor()->setRGB('10B981'); 
            },
        ];
    }
}