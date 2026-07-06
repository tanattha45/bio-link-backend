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
            if ($prevViews == 0 && $views > 0) {
                $growth = 100;
            } elseif ($prevViews > 0) {
                $growth = round((($views - $prevViews) / $prevViews) * 100, 1);
            }
            $growthFormatted = ($growth > 0 ? '+' : '') . $growth . '%';

            // 3. คำนวณ Performance Score 
            $baseScore = ($views * 1) + ($clicks * 5) + ($saves * 15);
            $growthBonus = min($growth, 50) * 0.01; 
            $finalScore = round($baseScore * (1 + $growthBonus), 2);

            // =========================================================
            // 🌟 4. ระบบค้นหา Popular Link (เจาะลึกถึงลิงก์ย่อย เหมือนใน Controller)
            // =========================================================
            
            // ดึงรายการคลิกทั้งหมดของโปรไฟล์นี้ (เพื่อเอา clicked_url มาเทียบ)
            $allClicks = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->get();

            // ฟังก์ชันทำความสะอาด URL
            $cleanUrl = function($u) {
                if (empty($u)) return '';
                $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
                $u = preg_replace('#^www\.#', '', $u);
                return strtolower(trim($u));
            };

            $popularTitle = 'ยังไม่มีข้อมูลคลิก';
            $popularUrl = '-';
            $popularClicks = 0;

            if ($allClicks->count() > 0) {
                $blocks = DB::table('blocks')->where('profile_id', $page->id)->get();
                $linksPerformance = collect();

                foreach ($blocks as $block) {
                    $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;

                    if (is_array($contentData)) {
                        foreach ($contentData as $item) {
                            $url = trim($item['url'] ?? $item['link'] ?? '');
                            if (empty($url)) continue;

                            // นับยอดคลิก
                            $clicksCount = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                                return (string)$click->block_id === (string)$block->id && 
                                       $cleanUrl($click->clicked_url) === $cleanUrl($url);
                            })->count();

                            $linksPerformance->push([
                                'title' => $item['name'] ?? $item['title'] ?? $block->title ?? 'ไม่มีชื่อลิงก์',
                                'clicks' => $clicksCount,
                                'url' => $url,
                                'icon' => $item['icon'] ?? $item['iconId'] ?? $block->icon ?? 'Link',
                                'type' => $block->type ?? ''
                            ]);
                        }
                    }
                }

                // กรอง YouTube / TikTok และ VIDEO ออก เหมือนหน้าบ้าน
                $filteredLinks = $linksPerformance->filter(function($link) {
                    $iconStr = strtolower($link['icon'] ?? '');
                    $typeStr = strtolower($link['type'] ?? '');
                    return !($iconStr === 'youtube' || $iconStr === 'tiktok' || $typeStr === 'video');
                });

                // จัดอันดับลิงก์ที่ถูกคลิกเยอะที่สุดมา 1 อัน
                $topLink = $filteredLinks->sortByDesc('clicks')->first();
                
                if ($topLink && $topLink['clicks'] > 0) {
                    $popularTitle = $topLink['title'];
                    $popularUrl = $topLink['url'];
                    $popularClicks = $topLink['clicks'];
                }
            }

            // แมปข้อมูลให้ตรงกับหัวคอลัมน์ใหม่
            return [
                'Profile Name'           => '@' . $page->username,
                'Views (Current Period)' => $views,
                'Views Growth (%)'       => $growthFormatted,
                'Clicks'                 => $clicks,
                'CTR (%)'                => $ctr . '%',
                'Saves'                  => $saves,
                'Popular Link Title'     => $popularTitle,       // 🌟 อัปเดตให้เป็นชื่อที่ถูกต้อง
                'Popular Link URL'       => $popularUrl,
                'Popular Link Clicks'    => $popularClicks,      // 🌟 อัปเดตเป็นจำนวนคลิกที่คำนวณใหม่
                'Performance Score'      => $finalScore,
                '_raw_score'             => $finalScore 
            ];
        })->sortByDesc('_raw_score')->map(function($item) {
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
            'Popular Link URL',
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