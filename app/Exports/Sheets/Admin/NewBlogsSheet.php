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

class NewBlogsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison, WithEvents
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
        // 🌟 1. ดึงบล็อกทั้งหมดในช่วงเวลาที่กำหนด (เพิ่มการ Join ตาราง users เพื่อกรอง Admin ออกให้ตรงกับ Sheet อื่น)
        $blocks = DB::table('blocks')
            ->join('profiles', 'blocks.profile_id', '=', 'profiles.id')
            ->join('users', 'profiles.user_id', '=', 'users.id')
            ->select('blocks.*', 'profiles.user_id as author_id', 'profiles.username')
            ->where('users.role', '!=', 'admin') // 🎯 ตัดผู้ใช้งานที่เป็น Admin ออก
            ->whereBetween('blocks.created_at', [$this->start, $this->end])
            ->get();

        // 2. ใช้ flatMap เพื่อแตก JSON content_data ออกเป็นหลายแถว
        return $blocks->flatMap(function ($block) {
            
            // จัดฟอร์แมตวันที่ให้สวยงาม
            $createdAt = Carbon::parse($block->created_at)->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s');
            $status = $block->is_visible ? 'Visible' : 'Hidden';
            $author = $block->username;

            $contents = [];
            if (!empty($block->content_data)) {
                $decoded = json_decode($block->content_data, true);
                if (is_array($decoded)) {
                    $contents = $decoded;
                }
            }
            
            // 🌟 กรณีที่ 1: ถ้าบล็อกนี้ไม่มีลิงก์ย่อยด้านใน หรือไม่ใช่ข้อมูลประเภท Array (เช่น TEXT, HEADER)
            if (empty($contents) || !is_array($contents)) {
                return [[
                    'created_at'   => $createdAt,
                    'block_id'     => $block->id,
                    'author'       => $author,
                    'status'       => $status,
                    'type'         => $block->type,
                    'title'        => $block->title ?? '-',
                    'content_name' => '-', // ไม่มีชื่อย่อย
                    'content_link' => $block->url ?? '-'  // ใช้ค่าลิ้งค์หลักของบล็อกถ้ามี
                ]];
            }

            // 🌟 กรณีที่ 2: ถ้าบล็อกนี้มีหลายลิงก์ (เช่น SHOP, SLIDER, SOCIAL, LINK) ให้วนลูปแตกแถว
            return collect($contents)->map(function ($item) use ($block, $createdAt, $author, $status) {
                $name = $item['name'] ?? $item['title'] ?? '-';
                
                // 🎯 ดึง url/link ครอบคลุมไปถึงเบอร์โทรศัพท์ อีเมล หรือรูปภาพของสินค้าชิ้นย่อยนั้นๆ
                $linkOrAddress = $item['url'] ?? $item['link'] ?? $item['image'] ?? $item['imageUrl'] ?? '-';

                return [
                    'created_at'   => $createdAt,
                    'block_id'     => $block->id,
                    'author'       => $author,
                    'status'       => $status,
                    'type'         => $block->type,
                    'title'        => $block->title ?? '-',
                    'content_name' => $name,
                    'content_link' => trim($linkOrAddress),
                ];
            });
        });
    }

    public function headings(): array
    {
        return [
            'วันที่สร้าง', 
            'Block ID', 
            'ผู้สร้าง', 
            'สถานะ',
            'ประเภท', 
            'หัวข้อบล็อก',  
            'ชื่อเนื้อหาย่อย', 
            'ลิงก์หรือที่อยู่เนื้อหา'
        ];
    }

    public function title(): string
    {
        return 'New Blogs Detail';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getTabColor()->setRGB('6B46FF'); 
            },
        ];
    }
}