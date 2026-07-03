<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OverviewExport;
use Carbon\Carbon;

class ExportController extends Controller
{
    public function exportReport(Request $request)
    {
        // 1. รับค่าจาก React Payload
        $timeRange = $request->input('timeRange');
        $customDate = $request->input('customDate');
        // กำหนดค่าเริ่มต้นเป็น array ว่างป้องกัน error กรณีไม่ได้ติ๊กอะไรเลย
        $options = $request->input('downloadOptions', []); 
        $format = $request->input('fileFormat', 'excel'); 

        // 2. คำนวณช่วงวันที่ (Start Date & End Date) และดัก Error กรณีลืมส่งวันที่
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

        // 3. ตรวจสอบว่าผู้ใช้ติ๊กเลือกข้อมูลอย่างน้อย 1 อย่าง
        if (!empty($options['overview']) || !empty($options['topProfiles']) || !empty($options['inactiveAccounts'])) {
            
            $fileName = 'Report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            
            // ส่ง $options ไปให้ OverviewExport เป็นพารามิเตอร์ตัวที่ 3
            return Excel::download(new OverviewExport($startDate, $endDate, $options), $fileName);
        }

        return response()->json(['message' => 'กรุณาเลือกข้อมูลที่ต้องการดาวน์โหลด'], 400);
    }
}