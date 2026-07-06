<?php

namespace App\Exports\Sheets\User;

use App\Models\Analytic;
use App\Models\Block;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize; 
use Illuminate\Support\Carbon;

class DailyOverviewSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $profileId, $startDate, $endDate, $blocks;

    public function __construct($profileId, $startDate, $endDate)
    {
        $this->profileId = $profileId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->blocks = Block::where('profile_id', $profileId)->get()->keyBy('id');
    }

    public function array(): array
    {
        $days = $this->startDate->diffInDays($this->endDate) + 1;
        $data = [];

        $allViews = Analytic::where('profile_id', $this->profileId)
            ->whereNull('block_id')
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();
            
        $allSaves = Analytic::where('profile_id', $this->profileId)
            ->where('block_id', 999999)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();

        $rawClicks = Analytic::where('profile_id', $this->profileId)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->get();

        $cleanUrl = function($u) {
            if (empty($u)) return '';
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        // กรอง Clicks เฉพาะที่ตรงกับลิงก์ที่มีอยู่จริง
        $filteredClicks = $rawClicks->filter(function($click) use ($cleanUrl) {
            $block = $this->blocks->get($click->block_id);
            if (!$block) return false;

            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) {
                        return true;
                    }
                }
            } else {
                $url = trim($block->url ?? '');
                if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) {
                    return true;
                }
            }
            return false;
        });

        for ($i = 0; $i < $days; $i++) {
            $date = $this->startDate->copy()->addDays($i)->format('Y-m-d');
            
            $views = $allViews->filter(fn($v) => Carbon::parse($v->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d') === $date)->count();
            
            // ดึงข้อมูลคลิกเฉพาะของวันนั้นๆ
            $dailyClicks = $filteredClicks->filter(fn($c) => Carbon::parse($c->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d') === $date);
            $clicks = $dailyClicks->count();
            
            $saves = $allSaves->filter(fn($s) => Carbon::parse($s->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d') === $date)->count();
            
            $ctr = $views > 0 ? round(($clicks / $views) * 100, 1) . '%' : '0%';

            // =========================================================
            // 🌟 ลอจิกใหม่: หาลิงก์ยอดนิยมประจำวัน
            // =========================================================
            $topLinkName = '-';
            $topLinkUrl = '-';

            if ($clicks > 0) {
                // 1. จัดกลุ่มและนับจำนวนตาม URL ที่คลิก แล้วเรียงจากมากไปน้อย
                $urlCounts = $dailyClicks->countBy('clicked_url')->sortDesc();
                
                // 2. ดึง URL ที่ได้อันดับ 1 ออกมา
                $topUrlRaw = $urlCounts->keys()->first(); 
                
                // 3. หาวันที่/เวลาที่มีคนคลิก URL นี้ เพื่อเอาไปหา Block ID
                $sampleClick = $dailyClicks->firstWhere('clicked_url', $topUrlRaw);

                if ($sampleClick) {
                    $topLinkUrl = $topUrlRaw; // ได้ URL แล้ว
                    $block = $this->blocks->get($sampleClick->block_id); // หาข้อมูลบล็อก

                    if ($block) {
                        $foundName = $block->title ?: 'ไม่มีชื่อบล็อก';
                        $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;

                        // 4. ค้นหาใน content_data เพื่อหาชื่อของรายการ (Item Name)
                        if (is_array($contentData)) {
                            foreach ($contentData as $item) {
                                $itemUrl = trim($item['url'] ?? $item['link'] ?? '');
                                if (!empty($itemUrl) && $cleanUrl($topUrlRaw) === $cleanUrl($itemUrl)) {
                                    $foundName = $item['name'] ?? $item['title'] ?? $foundName;
                                    break;
                                }
                            }
                        }
                        $topLinkName = $foundName; // ได้ชื่อแล้ว
                    }
                }
            }
            // =========================================================

            $data[] = [
                $date, 
                $views == 0 ? '0' : $views, 
                $clicks == 0 ? '0' : $clicks, 
                $saves == 0 ? '0' : $saves, 
                $ctr,
                $topLinkName, // 🌟 เพิ่มคอลัมน์ชื่อลิงก์ลงในแถว
                $topLinkUrl   // 🌟 เพิ่มคอลัมน์ URL ลงในแถว
            ];
        }

        return $data;
    }

    public function headings(): array { 
        return [
            'วันที่ (Date)', 
            'ยอดเข้าชมโปรไฟล์ (Views)', 
            'ยอดคลิกลิงก์รวม (Total Clicks)', 
            'ยอดเซฟคอนแทค (Save Contacts)', 
            'CTR (%)',
            'ชื่อลิงก์ยอดนิยม (Top Link Name)', // 🌟 เพิ่มหัวข้อ
            'URL ลิงก์ยอดนิยม (Top Link URL)'   // 🌟 เพิ่มหัวข้อ
        ]; 
    }
    
    public function title(): string { return 'ภาพรวมสถิติรายวัน'; }
}