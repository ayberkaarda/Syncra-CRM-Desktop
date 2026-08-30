<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * OKUNMA / İLETİM İMLEÇLERİ — HER YAZMA TEK BİR `UPDATE`
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * NEDEN OKU-DEĞİŞTİR-YAZ YAPILMIYOR
 * -----------------------------------------------------------------------------
 * Sohbet, sistemdeki en yüksek EŞZAMANLILIĞA sahip yüzeydir: aynı kullanıcı iki
 * sekmede açıkken iki `read` isteği, ya da bir gruba aynı anda gelen iki mesaj
 * sıradan bir durumdur. `$pivot->unread_count + 1` gibi bir PHP tarafı hesabı
 * klasik kayıp-güncelleme (lost update) yarışıdır: iki istek aynı değeri okur,
 * ikisi de aynı sonucu yazar, bir mesaj sayaçtan sessizce düşer. Bu yüzden
 * BURADAKİ HER YAZMA tek bir atomik `UPDATE`'tir ve yeni değer daima veritabanı
 * tarafında, mevcut satır değerinden türetilir.
 *
 * -----------------------------------------------------------------------------
 * NEDEN `GREATEST(COALESCE(...), ?)` — İMLEÇ GERİ GİTMEZ
 * -----------------------------------------------------------------------------
 * İmleçler MONOTONDUR. Ağ gecikmesi yüzünden sıra karışabilir: kullanıcı 90'a
 * kadar okuduğunu bildirdikten sonra, yolda kalmış eski bir "42'ye kadar
 * okudum" isteği sunucuya ulaşabilir. Düz atama bunu kabul eder ve okunmuş 48
 * mesaj yeniden okunmamış olur. `GREATEST` bu isteği ETKİSİZ hâle getirir —
 * imleç yalnızca ileri gider. `COALESCE(...,0)` ise kolonun `nullable` olması
 * içindir: `GREATEST(NULL, 42)` MySQL'de NULL döner ve imleci sıfırlardı.
 *
 * -----------------------------------------------------------------------------
 * `unread_count` NEDEN SIFIRLANMIYOR DA YENİDEN SAYILIYOR
 * -----------------------------------------------------------------------------
 * "Okundu" isteği her zaman EN SON mesaja kadar gelmez — kullanıcı yukarı
 * kaydırıp eski bir mesajın hizasında durabilir, ya da `read` isteği yoldayken
 * yeni bir mesaj gelip sayacı artırabilir. Düz `unread_count = 0` bu iki
 * durumda da yalan söyler (rozet söner ama okunmamış mesaj vardır). Bunun
 * yerine sayaç, imlecin YENİ değerinden sonra gelen ve kullanıcının KENDİSİNE
 * ait olmayan mesajlardan bağıntılı bir alt sorgu ile yeniden türetilir; sonuç
 * her koşulda doğrudur ve yine tek `UPDATE` içinde kalır.
 *
 * (MySQL/MariaDB `SET` atamalarını soldan sağa değerlendirir, yani alt sorgu
 * çalıştığında `cu.last_read_message_id` zaten yeni değerdir. Alt sorguda aynı
 * `GREATEST(...)` ifadesi TEKRAR yazıldığı için sonuç bu değerlendirme
 * sırasından BAĞIMSIZ olarak doğrudur — ifade idempotenttir.)
 *
 * -----------------------------------------------------------------------------
 * `m.user_id IS NULL` NEDEN OKUNMAMIŞ SAYILIR
 * -----------------------------------------------------------------------------
 * `type=system` mesajların göndereni yoktur (`user_id` null). SQL'de
 * `NULL <> 5` sonucu NULL'dur, yani düz bir eşitsizlik bu satırları sayımdan
 * SESSİZCE düşürürdü: "X gruba eklendi" satırı hiç kimsede okunmamış
 * görünmezdi. Açık `IS NULL OR` dalı bunu kapatır.
 */
final class ChatReadState
{
    /**
     * `POST /api/conversations/{conversation}/read`.
     *
     * Okuma, iletimi İMA EDER: bir mesajı okuyabilmek için almış olmak
     * gerekir. Bu yüzden aynı `UPDATE` iletim imlecini de en az aynı yere
     * taşır — aksi halde "okundu ama iletilmedi" gibi imkânsız bir ara durum
     * üretilebilirdi (istemci `delivered` isteğini atmadan `read` atarsa).
     *
     * @return array{last_read_message_id: int, last_delivered_message_id: int, unread_count: int}
     */
    public function markRead(int $conversationId, int $userId, int $messageId): array
    {
        DB::update(
            <<<'SQL'
                UPDATE conversation_user AS cu
                SET cu.last_read_message_id = GREATEST(COALESCE(cu.last_read_message_id, 0), ?),
                    cu.last_delivered_message_id = GREATEST(COALESCE(cu.last_delivered_message_id, 0), COALESCE(cu.last_read_message_id, 0), ?),
                    cu.unread_count = (
                        SELECT COUNT(*)
                        FROM messages AS m
                        WHERE m.conversation_id = cu.conversation_id
                          AND m.deleted_at IS NULL
                          AND (m.user_id IS NULL OR m.user_id <> cu.user_id)
                          AND m.id > GREATEST(COALESCE(cu.last_read_message_id, 0), ?)
                    ),
                    cu.updated_at = ?
                WHERE cu.conversation_id = ?
                  AND cu.user_id = ?
                SQL,
            [$messageId, $messageId, $messageId, now(), $conversationId, $userId],
        );

        return $this->cursorsFor($conversationId, $userId);
    }

    /**
     * `POST /api/conversations/{conversation}/delivered`.
     *
     * Yalnızca iletim imlecini ilerletir. `unread_count`'a DOKUNMAZ: bir mesajın
     * cihaza ulaşmış olması okunduğu anlamına gelmez, rozet yerinde kalmalıdır.
     *
     * @return array{last_read_message_id: int, last_delivered_message_id: int, unread_count: int}
     */
    public function markDelivered(int $conversationId, int $userId, int $messageId): array
    {
        DB::update(
            <<<'SQL'
                UPDATE conversation_user AS cu
                SET cu.last_delivered_message_id = GREATEST(COALESCE(cu.last_delivered_message_id, 0), ?),
                    cu.updated_at = ?
                WHERE cu.conversation_id = ?
                  AND cu.user_id = ?
                SQL,
            [$messageId, now(), $conversationId, $userId],
        );

        return $this->cursorsFor($conversationId, $userId);
    }

    /**
     * Yeni mesaj yayılımı — DİĞER katılımcıların sayacı tek `UPDATE` ile
     * ATOMİK olarak artırılır.
     *
     * `is_muted` olanlarınki de artar: susturma bir BİLDİRİM tercihidir
     * (`private-user.{id}` üzerinden rozet olayı gitmez), sohbetin içindeki
     * okunmamış sayısını yalanlamak değil. Kullanıcı sohbeti açtığında kaç
     * mesaj kaçırdığını görmelidir.
     */
    public function fanOutNewMessage(int $conversationId, int $senderId, int $messageId): void
    {
        DB::update(
            <<<'SQL'
                UPDATE conversation_user AS cu
                SET cu.unread_count = cu.unread_count + 1,
                    cu.updated_at = ?
                WHERE cu.conversation_id = ?
                  AND cu.user_id <> ?
                SQL,
            [now(), $conversationId, $senderId],
        );

        // Gönderen kendi mesajını yazmıştır: hem okumuş hem almış sayılır.
        // Bu satır olmasaydı kullanıcının kendi mesajı kendi rozetinde
        // "okunmamış"a dönüşmese bile, başka bir cihazdan gelen `read`
        // isteği imleci geriye taşımaya çalışırdı.
        DB::update(
            <<<'SQL'
                UPDATE conversation_user AS cu
                SET cu.last_read_message_id = GREATEST(COALESCE(cu.last_read_message_id, 0), ?),
                    cu.last_delivered_message_id = GREATEST(COALESCE(cu.last_delivered_message_id, 0), ?),
                    cu.unread_count = 0,
                    cu.updated_at = ?
                WHERE cu.conversation_id = ?
                  AND cu.user_id = ?
                SQL,
            [$messageId, $messageId, now(), $conversationId, $senderId],
        );
    }

    /**
     * Bir kullanıcının TÜM konuşmalarındaki toplam okunmamış sayısı ve
     * okunmamışı olan konuşma adedi — `GET /api/conversations/unread-count`.
     *
     * @return array{total_unread: int, conversation_count: int}
     */
    public function totalsFor(int $userId): array
    {
        $row = DB::table('conversation_user AS cu')
            ->join('conversations AS c', 'c.id', '=', 'cu.conversation_id')
            ->whereNull('c.deleted_at')
            ->where('cu.user_id', $userId)
            ->selectRaw('COALESCE(SUM(cu.unread_count), 0) AS total_unread, SUM(cu.unread_count > 0) AS conversation_count')
            ->first();

        return [
            'total_unread' => (int) ($row->total_unread ?? 0),
            'conversation_count' => (int) ($row->conversation_count ?? 0),
        ];
    }

    /**
     * Birden çok kullanıcının toplam okunmamış sayısı — TEK sorgu.
     *
     * `.chat.unread` olayı her alıcıya kendi GENEL rozet sayısını taşır; bunu
     * alıcı başına ayrı sorguyla üretmek 20 kişilik bir grupta mesaj başına 20
     * sorgu demekti.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> user_id => total_unread
     */
    public function totalsForMany(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('conversation_user AS cu')
            ->join('conversations AS c', 'c.id', '=', 'cu.conversation_id')
            ->whereNull('c.deleted_at')
            ->whereIn('cu.user_id', $ids)
            ->groupBy('cu.user_id')
            ->selectRaw('cu.user_id, COALESCE(SUM(cu.unread_count), 0) AS total_unread')
            ->get();

        $totals = array_fill_keys($ids, 0);

        foreach ($rows as $row) {
            $totals[(int) $row->user_id] = (int) $row->total_unread;
        }

        return $totals;
    }

    /**
     * Bir konuşmadaki katılımcıların pivot satırları (yayın gövdesi için).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{unread_count: int, is_muted: bool}>
     */
    public function pivotsFor(int $conversationId, array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('conversation_user')
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'unread_count', 'is_muted']);

        $pivots = [];

        foreach ($rows as $row) {
            $pivots[(int) $row->user_id] = [
                'unread_count' => (int) $row->unread_count,
                'is_muted' => (bool) $row->is_muted,
            ];
        }

        return $pivots;
    }

    /**
     * @return array{last_read_message_id: int, last_delivered_message_id: int, unread_count: int}
     */
    private function cursorsFor(int $conversationId, int $userId): array
    {
        $row = DB::table('conversation_user')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first(['last_read_message_id', 'last_delivered_message_id', 'unread_count']);

        return [
            'last_read_message_id' => (int) ($row->last_read_message_id ?? 0),
            'last_delivered_message_id' => (int) ($row->last_delivered_message_id ?? 0),
            'unread_count' => (int) ($row->unread_count ?? 0),
        ];
    }
}
