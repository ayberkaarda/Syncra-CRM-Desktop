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
        Schema::create('page_visit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('route');
            $table->string('path');
            $table->string('title')->nullable();
            $table->dateTime('entered_at');
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('session_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'entered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_visit_logs');
    }
};
