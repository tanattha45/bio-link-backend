<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Core Info
            $table->string('username', 50)->unique(); // ทำ Unique Index อัตโนมัติ
            $table->string('display_name', 100)->nullable();
            $table->text('bio')->nullable();
            
            // Media URLs
            $table->string('avatar_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('bg_image_url', 500)->nullable();
            
            // Save Contact (vCard) Info
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_company', 100)->nullable();
            $table->string('contact_job_title', 100)->nullable();
            $table->string('contact_website', 255)->nullable();
            $table->boolean('show_save_contact')->default(true);
            
            // Design Config
            $table->json('theme_config')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
