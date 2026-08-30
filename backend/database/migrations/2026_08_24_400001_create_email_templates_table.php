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
        // Not: softDeletes yok — şablonlar ya vardır ya yoktur; pasifleştirme
        // (is_active=false) "şu an kullanılmıyor" demek için yeterlidir ve
        // silme gerçek silmedir (şablona bağlı bir kayıt yoktur).
        //
        // Bu fazda E-POSTA GÖNDERİLMEZ (MAIL_MAILER=log, kapalı devre):
        // tablo yalnızca saklama + önizleme içindir.
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // Programatik anahtar (ör. `quote_sent`) — kodun şablonu adıyla
            // değil anahtarıyla bulması için; oluşturulduktan sonra değişmez.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->text('body_html');
            // Şablonda geçen `{{ degisken }}` yer tutucularının listesi;
            // önizleme ekranı örnek değerleri bunlardan üretir.
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
