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
        Schema::create('session_logs', function (Blueprint $table) {
            $table->id();
            // Başarısız girişlerde kullanıcı bilinmeyebilir.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Başarısız denemenin denediği e-posta.
            $table->string('email')->nullable();
            // login, logout, failed_login, locked_out
            $table->string('event')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('session_id')->nullable();
            $table->dateTime('logged_in_at')->nullable();
            $table->dateTime('logged_out_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_logs');
    }
};
