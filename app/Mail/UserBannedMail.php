<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class UserBannedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user; // ตัวแปรสำหรับเก็บข้อมูลผู้ใช้ที่ถูกแบน

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('แจ้งเตือน: บัญชีของคุณถูกระงับการใช้งาน (Account Suspended)')
                    ->view('emails.user_banned') // เรียกใช้หน้าตาอีเมลที่เราจะสร้างในขั้นตอนที่ 2
                    ->with([
                        'user' => $this->user,
                    ]);
    }
}