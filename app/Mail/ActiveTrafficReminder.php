<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; 
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActiveTrafficReminder extends Mailable implements ShouldQueue 
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
            subject: 'ข่าวดี! โปรไฟล์ของคุณยังมีคนเข้าชมอยู่นะ',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.active-traffic-reminder',
        );
    }
}