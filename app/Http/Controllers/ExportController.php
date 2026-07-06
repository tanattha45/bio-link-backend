<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OverviewExport;
use Carbon\Carbon;
use App\Exports\Sheets\Admin\UserManagementSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ExportController extends Controller
{
    public function exportReport(Request $request)
    {
        // 1. รับค่าจาก React Payload
        $timeRange = $request->input('timeRange');
        $customDate = $request->input('customDate');
        $options = $request->input('downloadOptions', []); 
        $format = $request->input('fileFormat', 'excel'); 

        // 2. คำนวณช่วงวันที่ (Start Date & End Date)
        if ($timeRange === 'custom') {
            if (!$customDate || empty($customDate['start']) || empty($customDate['end'])) {
                return response()->json(['message' => 'กรุณาระบุช่วงวันที่สำหรับกำหนดเอง'], 400);
            }
            $startDate = Carbon::parse($customDate['start']);
            $endDate = Carbon::parse($customDate['end']);
        } else {
            $startDate = Carbon::today();
            $endDate = Carbon::today();
            
            if ($timeRange === '7days') {
                $startDate = Carbon::today()->subDays(6);
            } elseif ($timeRange === '30days') {
                $startDate = Carbon::today()->subDays(29);
            }
        }

        // 3. เงื่อนไขสำหรับหน้า User Management (ติ๊กเลือก allUsers)
        if (!empty($options['allUsers'])) {
            $fileName = 'Users_Report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            
            // ใช้เทคนิคสร้างคลาส Multi-Sheet แบบด่วน (Inline) เพื่อห่อหุ้มคลาส Sheet เอาไว้ 
            // ทำให้เราไม่ต้องสร้างไฟล์ Export ด้านนอก และคุมให้มีเฉพาะแท็บผู้ใช้งานแท็บเดียวโดดๆ ได้
            return Excel::download(new class($startDate, $endDate) implements WithMultipleSheets {
                private $start; private $end;
                public function __construct($start, $end) { $this->start = $start; $this->end = $end; }
                public function sheets(): array {
                    return [
                        new UserManagementSheet($this->start, $this->end)
                    ];
                }
            }, $fileName);
        }

        // 4. เงื่อนไขเดิมสำหรับหน้า Dashboard (ติ๊กเลือกสถิติต่างๆ)
        if (!empty($options['overview']) || !empty($options['topProfiles']) || !empty($options['inactiveAccounts'])) {
            $fileName = 'Dashboard_Report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            
            return Excel::download(new OverviewExport($startDate, $endDate, $options), $fileName);
        }

        return response()->json(['message' => 'กรุณาเลือกข้อมูลที่ต้องการดาวน์โหลด'], 400);
    }
}