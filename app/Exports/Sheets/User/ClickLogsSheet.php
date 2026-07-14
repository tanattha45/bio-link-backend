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
            
            // ตัดลิงก์เว็บ Gmail
            $u = preg_replace('#^https?://mail\.google\.com/mail/\?view=cm&fs=1&to=#i', '', $u);
            
            // ตัดส่วนหัวอื่นๆ และขีดกลาง
            $u = preg_replace('#^https?://#', '', rtrim((string)$u, '/'));
            $u = preg_replace('#^www\.#', '', $u);
            $u = preg_replace('#^mailto:#i', '', $u);
            $u = preg_replace('#^tel:#i', '', $u);
            $u = str_replace('-', '', $u);
            
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

        // 🌟 1. เพิ่มฟังก์ชันทำความสะอาด URL ตรงนี้ด้วย เพื่อใช้จับคู่หาชื่อให้แม่นยำ
        $cleanUrl = function($u) {
            if (empty($u)) return '';
            $u = urldecode((string)$u);
            
            if (str_contains($u, 'mail.google.com') && str_contains($u, 'to=')) {
                $parts = explode('to=', $u);
                if (isset($parts[1])) {
                    $u = explode('&', $parts[1])[0];
                }
            }

            $u = preg_replace('#^https?://#i', '', rtrim($u, '/'));
            $u = preg_replace('#^www\.#i', '', $u);
            $u = preg_replace('#^mailto:#i', '', $u);
            $u = preg_replace('#^tel:#i', '', $u);
            $u = str_replace(['-', ' '], '', $u);
            
            return strtolower(trim($u));
        };

        // 🌟 2. ดึงชื่อสินค้า/รายการ (Item Name) ออกมา
        $itemName = '-';
        if ($block && $block->content_data) {
            $contentData = is_string($block->content_data) ? json_decode($block->content_data, true) : $block->content_data;
            
            if (is_array($contentData)) {
                // ค้นหารายการที่ URL ตรงกับที่คลิก
                foreach ($contentData as $item) {
                    $url = $item['url'] ?? $item['link'] ?? '';
                    
                    // 🌟 3. เปลี่ยนมาใช้ $cleanUrl ครอบทั้งสองฝั่งก่อนเปรียบเทียบ
                    if (!empty($url) && $cleanUrl($url) === $cleanUrl($analytic->clicked_url)) {
                        $itemName = $item['name'] ?? $item['title'] ?? '-';
                        break;
                    }
                }
            }
        }

        return [
            $analytic->created_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s'),
            $blockTitle,    
            $itemName,      // 🌟 ตอนนี้จะแสดงชื่อเบอร์โทรและอีเมลได้ถูกต้องแล้ว
            $type,          
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