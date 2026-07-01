<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; 
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InactiveUserReminder extends Mailable implements ShouldQueue 
{
    use Queueable, SerializesModels;

    public $user; // ตัวแปรเก็บข้อมูล User เพื่อเอาไปแสดงในอีเมล

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'คิดถึงจัง! กลับมาอัปเดตลิงก์โปรไฟล์ของคุณกันเถอะ 🎉',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.inactive-reminder',
        );
    }
}