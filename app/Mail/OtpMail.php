<?php

namespace App\Mail;

// รองรับระบบ Queue เพื่อกันไม่ให้หน้าเว็บของผู้ใช้ค้าง
use Illuminate\Bus\Queueable;

// Base Class ของระบบอีเมลใน Laravel
use Illuminate\Mail\Mailable;

// เนื้อหาจดหมาย
use Illuminate\Mail\Mailables\Content;

// หน้าซองจดหมาย
use Illuminate\Mail\Mailables\Envelope;

// ตัวแปลงใช้คู่กับ คิว เพื่อให้ดึงแค่ ID ของผู้ใช้งาน ไปเก้บในคิว
use Illuminate\Queue\SerializesModels;

use Illuminate\Contracts\Queue\ShouldQueue;

class OtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $otp; // ตัวแปรสำหรับส่งรหัส OTP เข้าไปในหน้าเว็บอีเมล

    public function __construct($otp)
    {
        // รับค่า $otp (จากฝั่ง Controller) เข้ามาเก็บไว้ในตัวแปรของคลาส
        $this->otp = $otp;
    }

    // หน้าซองจดหมาย 
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'รหัส OTP สำหรับตั้งรหัสผ่านใหม่ ✨',
        );
    }


    // เนื้อหาอีเมล
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp', // ชี้ไปที่ไฟล์หน้าตาอีเมลที่เราจะสร้างในข้อถัดไป
        );
    }

}
