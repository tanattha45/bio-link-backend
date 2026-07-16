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
        // 🌟 1. ดึงข้อมูลแบบรวบยอด (ลดภาระ Database) และกรองคนไม่มียอดออกตั้งแต่แรก
        $startDateStr = $this->start->toDateTimeString();
        $endDateStr = $this->end->toDateTimeString();
        $prevStartDateStr = $this->prevStart->toDateTimeString();
        $prevEndDateStr = $this->prevEnd->toDateTimeString();

        $rawData = DB::table('profiles')
            ->join('users', 'profiles.user_id', '=', 'users.id') 
            ->leftJoin('analytics', 'profiles.id', '=', 'analytics.profile_id')
            ->select(
                'profiles.id',
                'profiles.username',
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$startDateStr}' AND '{$endDateStr}' THEN 1 END) as views"),
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NULL AND analytics.created_at BETWEEN '{$prevStartDateStr}' AND '{$prevEndDateStr}' THEN 1 END) as prev_views"),
                DB::raw("COUNT(CASE WHEN analytics.block_id IS NOT NULL AND analytics.block_id != 999999 AND analytics.created_at BETWEEN '{$startDateStr}' AND '{$endDateStr}' THEN 1 END) as raw_clicks"),
                DB::raw("COUNT(CASE WHEN analytics.block_id = 999999 AND analytics.created_at BETWEEN '{$startDateStr}' AND '{$endDateStr}' THEN 1 END) as saves")
            )
            ->where('users.role', '!=', 'admin') // 🎯 ตัดแอดมินออก
            ->whereBetween('analytics.created_at', [$prevStartDateStr, $endDateStr])
            ->groupBy('profiles.id', 'profiles.username')
            ->havingRaw('views > 0 OR raw_clicks > 0 OR saves > 0') // 🎯 ตัดคนไม่มียอดออก
            ->get();

        // 🌟 2. ประมวลผลลัพธ์เพื่อกรอง Clicks ที่แท้จริง และหา Popular Link
        $processedData = $rawData->map(function ($page) use ($startDateStr, $endDateStr) {
            $allClicks = DB::table('analytics')
                ->where('profile_id', $page->id)
                ->whereNotNull('block_id')
                ->where('block_id', '!=', 999999)
                ->whereBetween('created_at', [$startDateStr, $endDateStr])
                ->get();

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
            $accurateClicks = 0; 

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

                $accurateClicks = $linksPerformance->sum('clicks');

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

            $views = (int)$page->views;
            $saves = (int)$page->saves;
            $prevViews = (int)$page->prev_views;
            
            $ctr = $views > 0 ? round((($accurateClicks + $saves) / $views) * 100, 1) : 0.0;
            
            $growth = 0;
            if ($prevViews == 0 && $views > 0) $growth = 100;
            elseif ($prevViews > 0) $growth = round((($views - $prevViews) / $prevViews) * 100, 1);

            return [
                'username'      => $page->username,
                'views'         => $views,
                'clicks'        => $accurateClicks,
                'saves'         => $saves,
                'ctr'           => $ctr,
                'growth'        => $growth,
                'popularTitle'  => $popularTitle,
                'popularUrl'    => $popularUrl,
                'popularClicks' => $popularClicks
            ];
        })->filter(function($item) {
            // กรองอีกชั้นกรณี Views=0, Saves=0 และ Clicks โดนตัดจนเหลือ 0
            return $item['views'] > 0 || $item['clicks'] > 0 || $item['saves'] > 0;
        });

        // 🌟 3. หาค่า Max สำหรับสูตร Normalization
        $maxViews = $processedData->max('views') ?: 0;
        $maxClicks = $processedData->max('clicks') ?: 0;
        $maxSaves = $processedData->max('saves') ?: 0;

        // 🌟 4. คำนวณคะแนน Performance Score แบบใหม่ และจัด Format สำหรับ Excel
        return $processedData->map(function($item) use ($maxViews, $maxClicks, $maxSaves) {
            
            $viewScore = ($maxViews > 0) ? ($item['views'] / $maxViews) * 100 * 0.40 : 0;
            $clickScore = ($maxClicks > 0) ? ($item['clicks'] / $maxClicks) * 100 * 0.35 : 0;
            $saveScore = ($maxSaves > 0) ? ($item['saves'] / $maxSaves) * 100 * 0.20 : 0;
            
            $growth = $item['growth'];
            $growthScore = 0;
            if ($growth < 0) {
                $growthScore = 0;
            } elseif ($growth <= 10) {
                $growthScore = 2;
            } elseif ($growth <= 20) {
                $growthScore = 4;
            } else {
                $growthScore = 5;
            }

            $finalScore = round($viewScore + $clickScore + $saveScore + $growthScore, 2);

            return [
                'Profile Name'           => '@' . $item['username'],
                'Views (Current Period)' => (int)$item['views'],
                'Views Growth (%)'       => ($growth > 0 ? '+' : '') . $growth . '%',
                'Clicks'                 => (int)$item['clicks'],
                'CTR (%)'                => $item['ctr'] . '%',
                'Saves'                  => (int)$item['saves'],
                'Popular Link Title'     => $item['popularTitle'],
                'Popular Link URL'       => $item['popularUrl'],
                'Popular Link Clicks'    => (int)$item['popularClicks'],
                'Performance Score'      => $finalScore,
                '_raw_score'             => $finalScore
            ];
        })->sortByDesc('_raw_score')->map(function($item) {
            unset($item['_raw_score']);
            return $item;
        })->values(); // Reset index เพื่อให้แถว Excel เรียงถูกต้อง
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