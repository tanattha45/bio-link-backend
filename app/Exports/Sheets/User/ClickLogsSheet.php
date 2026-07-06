<?php

namespace App\Exports\Sheets\User;

use App\Models\Analytic;
use App\Models\Block;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ClickLogsSheet implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $profileId, $startDate, $endDate, $blocks;

    public function __construct($profileId, $startDate, $endDate)
    {
        $this->profileId = $profileId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->blocks = Block::where('profile_id', $profileId)->get()->keyBy('id');
    }

    public function collection()
    {
        // ดึงข้อมูลดิบทั้งหมดจากฐานข้อมูลมาก่อน
        $allClicks = Analytic::where('profile_id', $this->profileId)
            ->whereNotNull('block_id')
            ->where('block_id', '!=', 999999)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'asc')
            ->get();

        // ฟังก์ชันทำความสะอาด URL เพื่อใช้เทียบค่า
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            return strtolower(trim($u));
        };

        // กรองข้อมูล (Filter) คัดเฉพาะคลิกที่ตรงกับลิงก์ที่มีอยู่จริงเท่านั้น
        return $allClicks->filter(function($click) use ($cleanUrl) {
            $block = $this->blocks->get($click->block_id);
            if (!$block) return false; // ถ้าบล็อกถูกลบไปแล้ว ให้ตัดทิ้ง

            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            
            // ตรวจสอบกับรายการย่อยในบล็อก
            if (is_array($contentData)) {
                foreach ($contentData as $item) {
                    $url = trim($item['url'] ?? $item['link'] ?? '');
                    if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) {
                        return true; // เก็บไว้ถ้ายืนยันได้ว่า URL ตรงกัน
                    }
                }
            } else {
                // กรณีบล็อกแบบเก่า
                $url = trim($block->url ?? '');
                if (!empty($url) && $cleanUrl($click->clicked_url) === $cleanUrl($url)) {
                    return true;
                }
            }
            
            return false; // ตัดคลิกที่ไม่เข้าเงื่อนไขทิ้ง
        });
    }

    public function map($analytic): array
    {
        $block = $this->blocks->get($analytic->block_id);
        $blockTitle = $block ? $block->title : 'ไม่มีชื่อบล็อก';
        $type = $block ? strtoupper($block->type) : 'UNKNOWN';

        // 🌟 ดึงชื่อสินค้า/รายการ (Item Name) ออกมา
        $itemName = '-';
        if ($block && $block->content_data) {
            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            
            if (is_array($contentData)) {
                // ค้นหารายการที่ URL ตรงกับที่คลิก
                foreach ($contentData as $item) {
                    $url = $item['url'] ?? $item['link'] ?? '';
                    if (!empty($url) && $url === $analytic->clicked_url) {
                        $itemName = $item['name'] ?? $item['title'] ?? '-';
                        break;
                    }
                }
            }
        }

        return [
            $analytic->created_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s'),
            $blockTitle,    // ชื่อบล็อกหลัก
            $itemName,      // 🌟 คอลัมน์ใหม่: ชื่อรายการที่ถูกคลิก
            $type,          // ประเภท
            $analytic->clicked_url,
            $analytic->referrer_url ?: '(Direct/เข้าโดยตรง)',
            $analytic->user_agent
        ];
    }

    public function headings(): array 
    { 
        return ['วัน-เวลาที่คลิก', 'ชื่อบล็อก', 'ชื่อรายการ/สินค้า', 'ประเภท', 'URL ที่คลิก', 'แหล่งที่มา (Referrer)', 'อุปกรณ์ / เบราว์เซอร์']; 
    }
    
    public function title(): string { return 'ข้อมูลคลิกรายครั้ง'; }
}