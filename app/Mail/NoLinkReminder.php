<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; 
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NoLinkReminder extends Mailable implements ShouldQueue 
{
    use Queueable, SerializesModels;

    public $user; 

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'เริ่มต้นสร้างหน้าโปรไฟล์ของคุณให้สมบูรณ์กันเถอะ! 🚀',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.no-link-reminder',
        );
    }
}