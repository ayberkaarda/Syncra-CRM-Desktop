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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('sku')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            // KDV oranı.
            $table->decimal('tax_rate', 5, 2)->default(20.00);
            $table->string('unit')->default('adet');
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
