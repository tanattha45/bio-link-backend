<?php

namespace App\Exports\Sheets\Admin;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class NewBlogsSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison,WithEvents
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
        // 1. ดึงบล็อกทั้งหมดในช่วงเวลาที่กำหนด
        // เรา JOIN กับ profiles เพื่อดึง user_id (Author ID) มาด้วย
        $blocks = DB::table('blocks')
            ->join('profiles', 'blocks.profile_id', '=', 'profiles.id')
            ->select('blocks.*', 'profiles.user_id as author_id')
            ->whereBetween('blocks.created_at', [$this->start, $this->end])
            ->get();

        // 2. ใช้ flatMap เพื่อแตก JSON content_data ออกเป็นหลายแถว
        return $blocks->flatMap(function ($block) {
            $contents = json_decode($block->content_data, true) ?? [];
            
            // ถ้า content_data ว่าง หรือไม่ใช่ array ให้แสดงแถวเดียว (ข้อมูลหลัก)
            if (empty($contents)) {
                return [[
                    $block->created_at, $block->id, $block->author_id, $block->type, 
                    $block->title, ($block->is_visible ? 'Visible' : 'Hidden'), '-', '-'
                ]];
            }

            // ถ้ามีข้อมูลย่อย ให้วนลูปแตกออกมา
            return collect($contents)->map(function ($item) use ($block) {
                // ดึงชื่อและ URL/Image จาก JSON (คีย์ใน JSON อาจต่างกัน เช่น 'name', 'title', 'url', 'image')
                $name = $item['name'] ?? $item['title'] ?? '-';
                $linkOrImage = $item['url'] ?? $item['image'] ?? $item['link'] ?? '-';

                return [
                    'created_at' => $block->created_at,
                    'block_id'   => $block->id,
                    'author_id'  => $block->author_id,
                    'is_visible' => $block->is_visible ? 'Visible' : 'Hidden',
                    'type'       => $block->type,
                    'title'      => $block->title ?? '-',
                    'content_name'=> $name,
                    'content_link'=> $linkOrImage,
                ];
            });
        });
    }

    public function headings(): array
    {
        return ['วันที่สร้าง', 
                'Block ID', 
                'ผู้สร้าง (Author ID)', 
                'สถานะ',
                'ประเภท', 
                'หัวข้อบล็อก',  
                'ชื่อเนื้อหา', 
                'ลิงก์หรือที่อยู่เนื้อหา'];
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