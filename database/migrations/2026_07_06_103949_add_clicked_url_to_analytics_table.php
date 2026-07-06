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
        Schema::table('analytics', function (Blueprint $table) {
            // 🌟 สร้างคอลัมน์ clicked_url โดยให้จัดตำแหน่งต่อจาก block_id
            $table->string('clicked_url')->nullable()->after('block_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analytics', function (Blueprint $table) {
            // 🌟 คำสั่งสำหรับลบคอลัมน์ทิ้ง (ในกรณีที่มีการกดย้อนกลับรหัส)
            $table->dropColumn('clicked_url');
        });
    }
};