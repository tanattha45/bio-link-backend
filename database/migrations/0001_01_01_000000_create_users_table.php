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
        Schema::create('users', function (Blueprint $table) {

            // id auto-incrementing primary key
            $table->id();

            // display name VARCHAR(100)
            $table->string('display_name', 100);

            // username VARCHAR(50) UNIQUE
            $table->string('username' , 50)->unique();

            // email VARCHAR(255) UNIQUE
            $table->string('email', 255)->unique();

            // password VARCHAR(255)
            $table->string('password', 255);

            //role ('admin', 'user') DEFAULT 'user'
            $table->enum('role', ['admin', 'user'])->default('user');

            // status (active, inactive) DEFAULT 'active'
            $table->enum('status', ['active', 'inactive'])->default('active');

            // timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
