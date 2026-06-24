<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // เพิ่มคอลัมน์ประเภท timestamp ชื่อ email_verified_at และอนุญาตให้เป็นค่าว่าง (nullable) ได้
            // วางไว้หลังคอลัมน์ email เพื่อความสวยงาม
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // คำสั่งสำหรับลบคอลัมน์ทิ้ง กรณีที่เราสั่ง Rollback
            $table->dropColumn('email_verified_at');
        });
    }
};