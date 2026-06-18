<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'type',
        'title',
        'display_order',
        'is_visible',
        'content_data',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'content_data' => 'array', // แปลง JSON เป็น Array
    ];

    // ความสัมพันธ์: 1 Block เป็นของ 1 Profile
    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }
}
