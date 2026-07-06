<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AnalyticsExport implements WithMultipleSheets
{
    use Exportable;

    protected $profileId;
    protected $startDate;
    protected $endDate;

    public function __construct($profileId, $startDate, $endDate)
    {
        $this->profileId = $profileId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function sheets(): array
    {
        return [
            new Sheets\DailyOverviewSheet($this->profileId, $this->startDate, $this->endDate),
            new Sheets\ClickLogsSheet($this->profileId, $this->startDate, $this->endDate),
            new Sheets\SaveContactLogsSheet($this->profileId, $this->startDate, $this->endDate),
        ];
    }
}