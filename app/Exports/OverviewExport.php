<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

use App\Exports\Sheets\Admin\SummarySheet; 
use App\Exports\Sheets\Admin\NewSignupsSheet;
use App\Exports\Sheets\Admin\NewBlogsSheet;
use App\Exports\Sheets\Admin\SavedActionsSheet;
use App\Exports\Sheets\Admin\TopPerformersSheet;
use App\Exports\Sheets\Admin\InactiveAccountsSheet;

class OverviewExport implements WithMultipleSheets
{
    use Exportable;

    protected $startDate;
    protected $endDate;
    protected $options; 

    // 2. รับค่า $options เข้ามาใน Constructor
    public function __construct($startDate, $endDate, $options = [])
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->options = $options;
    }

    public function sheets(): array
    {
        $sheets = [];

        // 3. ตรวจสอบว่าติ๊ก "สถิติภาพรวมระบบ (overview)" มาไหม
        // ถ้าติ๊ก ให้ใส่ 4 Sheet แรกเข้าไป
        if (isset($this->options['overview']) && $this->options['overview'] === true) {
            $sheets[] = new SummarySheet($this->startDate, $this->endDate);
            $sheets[] = new NewSignupsSheet($this->startDate, $this->endDate);
            $sheets[] = new NewBlogsSheet($this->startDate, $this->endDate);
            $sheets[] = new SavedActionsSheet($this->startDate, $this->endDate);
        }

        // 4. ตรวจสอบว่าติ๊ก "โปรไฟล์ที่มีผลงานดีที่สุด (topProfiles)" มาไหม
        // ถ้าติ๊ก ให้ใส่ Sheet TopPerformers เข้าไป
        if (isset($this->options['topProfiles']) && $this->options['topProfiles'] === true) {
            $sheets[] = new TopPerformersSheet($this->startDate, $this->endDate);
        }

        // Inactive Accounts 
         if (isset($this->options['inactiveAccounts']) && $this->options['inactiveAccounts'] === true) {
             $sheets[] = new InactiveAccountsSheet($this->startDate, $this->endDate);
        }

        return $sheets;
    }
}