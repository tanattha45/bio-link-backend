<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    // ตัวแปรสำหรับเก็บลิงก์ยืนยันตัวตน เพื่อส่งต่อให้ Blade Template
    public $verificationUrl;

    public function __construct($verificationUrl)
    {
        $this->verificationUrl = $verificationUrl;
    }

    public function build()
    {
        return $this->subject('กรุณายืนยันอีเมลของคุณเพื่อเปิดใช้งานบัญชี')
                    ->view('emails.verify_email'); // ชี้ไปยังไฟล์หน้าตาอีเมล
    }
}