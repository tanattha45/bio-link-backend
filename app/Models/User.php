<?php

namespace App\Models;

// นำเข้า class User ของ Laravel มาในชื่อของ Authenticatable
use Illuminate\Foundation\Auth\User as Authenticatable;

// เกี่ยวกับการแจ้งเตือน
use Illuminate\Notifications\Notifiable;

// สำหรับการจัดการ Token ในการยืนยันตัวตนของผู้ใช้
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    // อนุญาติให้ controller บันทึกข้อมูลลง column พวกนี้ได้โดยตรง
    protected $fillable = [
        'display_name',
        'username',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */

    // เป็นส่วนที่เมื่อ return เป็น JSON แล้วจะไม่แสดงในส่วนนี้เพื่อความปลอดภัย
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    // cast เหมือนวุ้นแปลภาษาแปลงข้อมูลให้สะดวกต่อการใช้งาน
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
