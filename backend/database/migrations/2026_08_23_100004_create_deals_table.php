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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->index();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            // pipeline_stage silinemez (restrictOnDelete) — kayıp/sahipsiz deal oluşmasın.
            $table->foreignId('pipeline_stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            // Fractional index: kartlar arasına ekleme toplu renumbering gerektirmesin (örn. "aa", "ab", "b").
            $table->string('position', 64);
            // Optimistic locking: eşzamanlı kart taşıma çakışmalarını (iki kullanıcı aynı deal'i aynı anda güncellerse) tespit etmek için.
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('probability')->nullable();
            $table->date('expected_close_date')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->string('status')->default('open')->index();
            $table->string('lost_reason')->nullable();
            $table->string('won_reason')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Kanban sorgusunun temeli: bir aşamadaki kartları position sırasıyla çekmek için (ROADMAP R4).
            $table->index(['pipeline_stage_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
