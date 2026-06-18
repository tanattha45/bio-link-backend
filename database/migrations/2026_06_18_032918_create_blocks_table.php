<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();

            // เชื่อมกับตาราง profiles (ถ้าลบ profile บล็อกจะโดนลบไปด้วย)
            $table->foreignId('profile_id')->constrained('profiles')->onDelete('cascade');
            
            // ประเภทกล่อง ('LINK', 'YOUTUBE', 'TIKTOK', 'SHOP')
            $table->string('type', 50); 
            
            // หัวข้อบล็อก
            $table->string('title', 255)->nullable(); 
            
            // ตัวเลขสำหรับจัดเรียง (Drag & Drop)
            $table->integer('display_order')->default(0); 
            
            // สถานะเปิด/ปิดกล่อง
            $table->boolean('is_visible')->default(true);

            // ข้อมูลย่อยของแต่ละ block
            $table->json('content_data')->nullable();

            $table->timestamps();
           
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
