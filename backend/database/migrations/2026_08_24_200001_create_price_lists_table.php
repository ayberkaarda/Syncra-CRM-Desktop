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
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Ör. PERAKENDE, TOPTAN — insan-okunur, benzersiz kod.
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('TRY');
            // Yalnızca bir liste varsayılan olabilir — tekillik PriceListService
            // içinde transaction ile garanti edilir (contacts.is_primary deseni).
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
