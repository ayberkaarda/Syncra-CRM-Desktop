<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 12 — çift tik durum makinesinin "iletildi" ayağı.
 *
 * -----------------------------------------------------------------------------
 * NEDEN MESAJ BAŞINA SATIR AÇILMADI (`message_user` TABLOSU YOK)
 * -----------------------------------------------------------------------------
 * "Kim hangi mesajı aldı/okudu" sorusunu mesaj × katılımcı kesişiminde bir
 * satırla tutmak, chat gibi ürünün EN YÜKSEK HACİMLİ tablosunda satır sayısını
 * katılımcı sayısıyla çarpar: 10 kişilik bir grupta 100.000 mesaj 1.000.000
 * durum satırı demektir ve her mesaj gönderimi N satırlık bir INSERT'e döner.
 *
 * Oysa okuma/iletim MONOTONDUR: bir kullanıcı 42 numaralı mesajı okuduysa
 * 41'i de okumuştur. Bu yüzden katılımcı başına TEK bir imleç yeterlidir ve
 * pivot satırı (konuşma × kullanıcı) zaten mevcuttur. `last_read_message_id`
 * Faz 3'te vardı; buraya yalnızca ikizi olan `last_delivered_message_id`
 * ekleniyor. Üç durum bu iki imleçten TÜRETİLİR (bkz. App\Services\Chat\
 * TickState):
 *
 *   sent      -> mesaj kalıcı (id + created_at var)
 *   delivered -> en az bir DİĞER katılımcının imleci >= message.id
 *   read      -> en az bir DİĞER katılımcının okuma imleci >= message.id
 *
 * `nullOnDelete`: `last_read_message_id` ile birebir aynı davranış. Bir mesaj
 * KALICI olarak silinirse (forceDelete) imleç geçersiz bir id'ye işaret
 * etmemeli; null'a düşmek "hiç iletilmedi" anlamına gelir ve tik en fazla bir
 * kademe geriler — asla FK ihlali üretmez. Normal (soft) silmede satır yerinde
 * kaldığı için imleç de bozulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->foreignId('last_delivered_message_id')
                ->nullable()
                ->after('last_read_message_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->dropForeign(['last_delivered_message_id']);
            $table->dropColumn('last_delivered_message_id');
        });
    }
};
