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
        if (is_array($this->content_data) && isset($this->content_data['icon'])) {
            return $this->content_data['icon'];
        }
        
        $typeMap = [
            'YOUTUBE' => 'Youtube',
            'TIKTOK'  => 'TikTok',
            'IMAGE'   => 'Image',
            'SHOP'    => 'Shop',
            'LINK'    => 'Link',
            
            'GRID2'   => 'Grid2',
            'GRID3'   => 'Grid3',
            'SLIDER'  => 'Slider',
        ];
        
        return $typeMap[strtoupper($this->type)] ?? 'Link';
    }

    public function getIsVisibleAttribute()
    {
        return isset($this->attributes['is_visible']) ? (bool) $this->attributes['is_visible'] : true;
    }
}