<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Analytic extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'block_id',
        'session_id',
        'ip_address',
        'user_agent',
        'referrer_url',
    ];

    // บอก Laravel ว่า สถิติตัวนี้ เป็นของโปรไฟล์ไหน
    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }
}