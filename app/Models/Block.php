<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'type', 'title', 'display_order', 'is_visible', 'content_data',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'content_data' => 'array', 
    ];

    protected $appends = ['items', 'icon', 'isVisible'];

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    // =========================================================
    // Accessors: แปลงร่างข้อมูลให้ตรงกับที่ React ต้องการแบบเป๊ะๆ
    // =========================================================

    public function getItemsAttribute()
    {
        if (is_array($this->content_data) && isset($this->content_data['items'])) {
            return $this->content_data['items'];
        }
        return is_array($this->content_data) ? $this->content_data : [];
    }

    public function getIconAttribute()
    {
        // 1. ถ้ามี icon เซ็ตมาแล้วใน content_data ให้ยึดตามนั้น
        if (is_array($this->content_data) && isset($this->content_data['icon'])) {
            return $this->content_data['icon'];
        }
        
        // 2. ถ้าไม่มี แปลงจาก type (เผื่อการดึงข้อมูลเก่า)
        $typeMap = [
            'YOUTUBE' => 'Youtube',
            'TIKTOK'  => 'TikTok',
            'IMAGE'   => 'Image',
            'SHOP'    => 'Shop',
            'LINK'    => 'Link'
        ];
        return $typeMap[strtoupper($this->type)] ?? 'Link';
    }

    public function getIsVisibleAttribute()
    {
        return isset($this->attributes['is_visible']) ? (bool) $this->attributes['is_visible'] : true;
    }
}