<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Carbon\Carbon;

class CleanupUnverifiedUsers extends Command
{
    // ตั้งชื่อคำสั่งที่จะใช้รัน
    protected $signature = 'users:cleanup-unverified';

    // คำอธิบายคำสั่ง
    protected $description = 'ลบผู้ใช้ที่ยังไม่ยืนยันอีเมลและสร้างบัญชีมาเกิน 7 วัน';

    public function handle()
    {
        // คำนวณเวลาย้อนหลังกลับไป 7 วัน
        $sevenDaysAgo = Carbon::now()->subDays(7);

        // ค้นหาและลบ User ที่ email_verified_at เป็น null และสร้างมาแล้วเกิน 7 วัน
        $deletedCount = User::whereNull('email_verified_at')
                            ->where('created_at', '<=', $sevenDaysAgo)
                            ->delete();

        // แสดงข้อความใน Terminal ว่าลบไปกี่คน
        $this->info("ทำความสะอาดฐานข้อมูลเรียบร้อย ลบบัญชีขยะไปทั้งหมด: {$deletedCount} บัญชี");
    }
}