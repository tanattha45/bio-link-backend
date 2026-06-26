<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash; 

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // สร้างบัญชี Admin เริ่มต้น (ถ้ามีอีเมลนี้อยู่แล้วจะไม่สร้างซ้ำ)
        User::firstOrCreate(
            ['email' => 'admin@system.com'], //  กำหนดอีเมล Admin ตรงนี้
            [
                'display_name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => Hash::make('password123'), // กำหนดรหัสผ่านตรงนี้ (Hash::make จะช่วยเข้ารหัสให้)
                'role' => 'admin',                       //  กำหนดให้เป็น admin 
                'status' => 'active',
                'email_verified_at' => now(),            //  ประทับตรายืนยันอีเมลให้เลย ไม่ต้องรอคลิกลิงก์
            ]
        );
    }
}