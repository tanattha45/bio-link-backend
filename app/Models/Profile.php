<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany; // ⭐️ อย่าลืม import HasMany

class Profile extends Model
{
    protected $fillable = [
        'user_id', 
        'username', 
        'display_name', 
        'bio', 
        'avatar_url', 
        'cover_url',
        'bg_image_url', // ⭐️ แอบเห็นว่าใน Resource มีเรียกใช้ เลยเติมให้ด้วยครับ
        'contact_name', 
        'contact_phone', 
        'contact_email', 
        'contact_company', 
        'contact_job_title', 
        'contact_website', 
        'show_save_contact',
        'theme_config' // ⭐️ เติมให้ด้วย ป้องกัน error mass assignment
    ];

    protected $casts = [
        'show_save_contact' => 'boolean',
        'theme_config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ⭐️ เพิ่มความสัมพันธ์: 1 Profile มีได้หลาย Block (ปุ่มลิงก์)
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }
}