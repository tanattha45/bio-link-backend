<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('analytics', function (Blueprint $table) {
            $table->id();
            
            // ผูกกับตาราง profiles (ถ้าลบโปรไฟล์ สถิติจะถูกลบตามอัตโนมัติ)
            $table->foreignId('profile_id')->constrained('profiles')->onDelete('cascade');
            
            // ผูกกับบล็อก/ลิงก์ (เป็น null ได้ กรณีที่เขาแค่เข้ามาดูหน้าเว็บ ไม่ได้กดคลิกลิงก์)
            $table->unsignedBigInteger('block_id')->nullable(); 

            $table->string('session_id', 255); // ใช้แยกผู้เข้าชม ป้องกันการกด F5 ปั่นวิว
            $table->string('ip_address', 45)->nullable(); // เก็บ IP แบบเข้ารหัส
            $table->text('user_agent')->nullable(); // เก็บประเภทอุปกรณ์ (มือถือ/คอม)
            $table->string('referrer_url', 255)->nullable(); // แหล่งที่มาจากเว็บอื่น เช่น Facebook, TikTok
            
            $table->timestamps(); // สร้าง created_at และ updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('analytics');
    }
};