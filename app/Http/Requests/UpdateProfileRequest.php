<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // อนุญาตให้ใช้งาน (เช็ค Auth ผ่าน Middleware แล้ว)
    }

    public function rules(): array
    {
        $userId = $this->user()->id; // ดึง ID ของ User ปัจจุบัน

        return [
            // อนุญาต a-z, 0-9, - และ _ เท่านั้น ห้ามเว้นวรรค
            'username' => 'sometimes|string|max:50|regex:/^[a-zA-Z0-9_-]+$/|unique:profiles,username,' . $this->user()->profile->id,
            'display_name' => 'nullable|string|max:100',
            'bio' => 'nullable|string',
            'contact_email' => 'nullable|email|max:255',
            'show_save_contact' => 'boolean',
            'theme_config' => 'nullable|array',
            // URL validation
            'avatar_url'   => 'nullable|string',
            'cover_url'    => 'nullable|string',
            'contact_phone' => 'nullable|string|max:20',
        ];
    }
}
