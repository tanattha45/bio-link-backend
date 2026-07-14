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

class TopPerformersSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison, WithEvents
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
        // 🌟 1. เพิ่มการ Join ตาราง users เพื่อกรอง Admin ออกจากการจัดอันดับ Top Performers
        $data = DB::table('profiles')
            ->join('users', 'profiles.user_id', '=', 'users.id') 
            ->leftJoin('analytics', 'profiles.id', '=', 'analytics.profile_id')
            ->select(
                'profiles.id',
                'profiles.username'
            )
            ->where('users.role', '!=', 'admin') // 🎯 ตัดแอดมินออก
            ->whereBetween('analytics.created_at', [$this->prevStart, $this->end])
            ->groupBy('profiles.id', 'profiles.username')
            ->get();

        return $data->map(function ($page) {
            // ดึง Views
            $views = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->whereNull('block_id')
                ->whereBetween('created_at', [$this->start, $this->end])
                ->count();

            $prevViews = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->whereNull('block_id')
                ->whereBetween('created_at', [$this->prevStart, $this->prevEnd])
                ->count();

            $saves = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->where('block_id', 999999)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->count();

            // 🌟 ระบบค้นหา Popular Link และยอดคลิกที่แท้จริง
            $allClicks = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->get();

            // 🌟 2. อัปเดตฟังก์ชันตัวทำความสะอาด URL ให้รองรับเบอร์โทร และอีเมล
            $cleanUrl = function($u) {
                if (empty($u)) return '';
                
                $u = preg_replace('#^https?://mail\.google\.com/mail/\?view=cm&fs=1&to=#i', '', $u);
                $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
                $u = preg_replace('#^www\.#', '', $u);
                $u = preg_replace('#^mailto:#i', '', $u);
                $u = preg_replace('#^tel:#i', '', $u);
                $u = str_replace('-', '', $u);
                
                return strtolower(trim($u));
            };

            $popularTitle = 'ยังไม่มีข้อมูลคลิก';
            $popularUrl = '-';
            $popularClicks = 0;
            $accurateClicks = 0; // ยอดคลิกที่ผ่านการกรองแล้ว

            if ($allClicks->count() > 0) {
                $blocks = DB::table('blocks')->where('profile_id', $page->id)->get();
                $linksPerformance = collect();

                foreach ($blocks as $block) {
                    $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;

                    if (is_array($contentData)) {
                        foreach ($contentData as $item) {
                            $url = trim($item['url'] ?? $item['link'] ?? '');
                            if (empty($url)) continue;

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
                    } else {
                        // 🌟 เผื่อกรณีที่เป็นบล็อกลิงก์เดี่ยว ไม่ใช่ Array
                        $url = trim($block->url ?? '');
                        if (!empty($url)) {
                            $clicksCount = $allClicks->filter(function($click) use ($block, $url, $cleanUrl) {
                                return (string)$click->block_id === (string)$block->id && 
                                       $cleanUrl($click->clicked_url) === $cleanUrl($url);
                            })->count();

                            $linksPerformance->push([
                                'title' => $block->title ?? 'ไม่มีชื่อลิงก์',
                                'clicks' => $clicksCount,
                                'url' => $url,
                                'icon' => $block->icon ?? 'Link',
                                'type' => $block->type ?? ''
                            ]);
                        }
                    }
                }

                // สรุปยอดคลิกที่ถูกต้อง (ตัดลิงก์ที่ไม่มีอยู่จริงออก)
                $accurateClicks = $linksPerformance->sum('clicks');

                // กรองสำหรับ Popular Link
                $filteredLinks = $linksPerformance->filter(function($link) {
                    $iconStr = strtolower($link['icon'] ?? '');
                    $typeStr = strtolower($link['type'] ?? '');
                    return !($iconStr === 'youtube' || $iconStr === 'tiktok' || $typeStr === 'video');
                });

                $topLink = $filteredLinks->sortByDesc('clicks')->first();
                if ($topLink && $topLink['clicks'] > 0) {
                    $popularTitle = $topLink['title'];
                    $popularUrl = $topLink['url'];
                    $popularClicks = $topLink['clicks'];
                }
            }

            // คำนวณสถิติ
            $ctr = $views > 0 ? round((($accurateClicks+(int)$saves) / $views) * 100, 1) : 0.0;
            $growth = 0;
            if ($prevViews == 0 && $views > 0) $growth = 100;
            elseif ($prevViews > 0) $growth = round((($views - $prevViews) / $prevViews) * 100, 1);
            
            $baseScore = ($views * 1) + ($accurateClicks * 5) + ($saves * 15);
            $growthBonus = min($growth, 50) * 0.01; 
            $finalScore = round($baseScore * (1 + $growthBonus), 2);

            return [
                'Profile Name'           => '@' . $page->username,
                'Views (Current Period)' => (int)$views,
                'Views Growth (%)'       => ($growth > 0 ? '+' : '') . $growth . '%',
                'Clicks'                 => (int)$accurateClicks, // ใช้ยอดที่กรองแล้ว
                'CTR (%)'                => $ctr . '%',
                'Saves'                  => (int)$saves,
                'Popular Link Title'     => $popularTitle,
                'Popular Link URL'       => $popularUrl,
                'Popular Link Clicks'    => (int)$popularClicks,
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