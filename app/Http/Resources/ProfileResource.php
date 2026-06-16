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
            'bio' => $this->bio,
            'images' => [
                'avatar' => $this->avatar_url,
                'cover' => $this->cover_url,
                'background' => $this->bg_image_url,
            ],
            'contact' => [
                'is_enabled' => $this->show_save_contact,
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
                'company' => $this->contact_company,
                'job_title' => $this->contact_job_title,
                'website' => $this->contact_website,
            ],
            'theme' => $this->theme_config ?? [], // จัดการค่า null ให้เป็น Array ว่าง
        ];
    }
}