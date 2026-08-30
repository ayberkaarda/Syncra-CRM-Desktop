<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/QUOTE-FINANCIALS.md §5 — dört kolon, tek migration.
 *
 * `discount_amount` DEĞİŞMEZ ve kalır: uygulanan indirimin TL karşılığıdır ve
 * her zaman QuoteCalculator tarafından yazılır. Tüm matematik ve raporlama
 * onun üzerinden döndüğü için mevcut sorgular kırılmaz. Yeni
 * `discount_type`/`discount_value` çifti yalnızca KULLANICININ GİRİŞ BİÇİMİNİ
 * saklar: "%5 kır" denip kalem eklendiğinde indirimin yeniden hesaplanabilmesi
 * için ham yüzdenin korunması şarttır — yalnızca TL tutar saklansaydı kalem
 * değişince yüzde anlamını yitirirdi.
 *
 * `parent_quote_id` + `revision`: gönderilmiş bir teklifin tutarı kilitlidir
 * (QuoteService::assertAmountsEditable), değişiklik yeni bir teklif gerektirir.
 * Bu iki kolon o yeni teklifi eskisine BAĞLAR; olmasaydı "QTE-000007'nin 2.
 * revizyonu" bilgisi ve kaç tur pazarlık döndüğü kalıcı olarak kaybolurdu.
 *
 * DİKKAT (ROADMAP R12): kolon metotları (`nullable`, `after`) FK/davranış
 * metotlarından (`constrained`, `nullOnDelete`) ÖNCE zincirlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // 'amount' | 'percent' — Rule::in ile uygulama katmanında
            // kısıtlanır. ENUM kolonu KULLANILMADI: MySQL'de ENUM'a değer
            // eklemek tablo değiştirmek demektir ve projedeki diğer durum
            // kolonları (quotes.status, tickets.status) da string'dir.
            $table->string('discount_type')->default('amount')->after('discount_amount');
            $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');

            // Zincir: her revizyon BİR ÖNCEKİNİ gösterir (köke değil), böylece
            // pazarlık turlarının sırası kaybolmaz.
            $table->foreignId('parent_quote_id')->nullable()->after('deal_id')
                ->constrained('quotes')->nullOnDelete();
            $table->unsignedSmallInteger('revision')->default(1)->after('parent_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['parent_quote_id']);
            $table->dropColumn(['discount_type', 'discount_value', 'parent_quote_id', 'revision']);
        });
    }
};
