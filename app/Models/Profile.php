<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'username', 'display_name', 'bio', 
        'avatar_url', 'cover_url', 'bg_image_url',
        'contact_name', 'contact_phone', 'contact_email', 
        'contact_company', 'contact_job_title', 'contact_website', 
        'show_save_contact', 'theme_config'
    ];

    // บอก Laravel ว่าคอลัมน์นี้เป็น JSON Array / Boolean ป้องกันบั๊ก Data Type
    protected $casts = [
        'show_save_contact' => 'boolean',
        'theme_config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
