<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // ใช้คำสั่ง ->change() เพื่อบอก Laravel ว่าเราต้องการอัปเดตของเดิม
        $table->enum('status', ['active', 'inactive', 'banned'])->default('active')->change();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        // ย้อนกลับไปเป็นแบบเดิมหากมีการ Rollback
        $table->enum('status', ['active', 'inactive'])->default('active')->change();
    });
}
};
