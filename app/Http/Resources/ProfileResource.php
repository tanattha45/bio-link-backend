<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
        'id' => $this->id,
        'username' => $this->username,
        'display_name' => $this->display_name,
        'name' => $this->display_name, 
        'bio' => $this->bio,
        
        'avatar' => $this->avatar_url,
        'cover' => $this->cover_url,
        'background' => $this->bg_image_url,
        
        // บังคับแปลงเป็น Boolean ให้ React เอาไปใช้ง่ายๆ
        'show_save_contact' => (bool) $this->show_save_contact, 
        
        // ส่งข้อมูลติดต่อแบบแยกให้ชัดเจน (เผื่อ React ใช้ทั้งแบบสั้นและ CamelCase)
        'contactName' => $this->contact_name,
        'contact_name' => $this->contact_name,
        'phone' => $this->contact_phone,
        'email' => $this->contact_email,
        'company' => $this->contact_company,
        'title' => $this->contact_job_title,
        'website' => $this->contact_website,
        
        'theme' => $this->theme_config ?? [],
        'blocks' => $this->whenLoaded('blocks'),
    ];
    }
}