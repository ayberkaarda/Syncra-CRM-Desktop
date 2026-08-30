<?php

namespace App\Services\Chat;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * ÇİFT TİK DURUM MAKİNESİ — pivottan TÜRETİLİR, mesaj başına satır YOK
 * =============================================================================
 *
 * Üç durum vardır ve hepsi `conversation_user` pivotundaki İKİ imleçten
 * hesaplanır:
 *
 *   sent      mesaj kalıcı (id + created_at var) — her zaman doğru olan taban
 *             durum.
 *   delivered en az bir DİĞER katılımcının `last_delivered_message_id` imleci
 *             mesajın id'sine ulaşmış.
 *   read      en az bir DİĞER katılımcının `last_read_message_id` imleci
 *             mesajın id'sine ulaşmış.
 *
 * GRUPTA "EN AZ BİR" KURALI: WhatsApp grup sohbetinde ikinci tik yalnızca
 * HERKES aldığında mavileşir. Burada bilinçli olarak gevşetildi — 12 kişilik
 * bir grupta izinli tek bir kişi yüzünden mesaj günlerce tek tik kalır ve
 * gösterge bilgi taşımayı bırakır. "Kim okudu" ayrıntısı grup detayında ayrı
 * bir uçtan sorulabilir; bu fazın kapsamı değildir.
 *
 * -----------------------------------------------------------------------------
 * N+1 YOK — KONUŞMA BAŞINA TEK SORGU, EŞLEME BELLEKTE
 * -----------------------------------------------------------------------------
 * Naif çözüm her mesaj için "başkası bunu okudu mu" sorgusu atardı: 30 mesajlık
 * bir sayfada 30 sorgu. Bunun yerine imleçlerin MONOTONLUĞU kullanılır — imleç
 * ilerledikçe ALTINDAKİ tüm mesajlar da o duruma geçmiştir. Dolayısıyla bir
 * konuşma için tek bir `MAX(...)` çifti yeterlidir ve mesaj listesi üzerinde
 * yalnızca iki tamsayı karşılaştırması yapılır.
 *
 * `$subjectId` (dışlanan kullanıcı) neden alanda saklanıyor: durum "DİĞER"
 * katılımcılara göre tanımlı ve "diğer" kelimesi bağlama göre değişir —
 * HTTP listesinde bakan kullanıcı, yayın (broadcast) gövdesinde mesajın
 * göndereni. Aynı sınıf iki bağlamda da kullanılabilsin diye kimin dışlandığı
 * durumun kendisiyle birlikte taşınır.
 */
final class TickState
{
    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public function __construct(
        public readonly int $subjectId,
        public readonly int $deliveredUpTo,
        public readonly int $readUpTo,
    ) {}

    /**
     * Hiç katılımcısı olmayan / imleci ilerlememiş konuşma için nötr durum.
     */
    public static function empty(int $subjectId): self
    {
        return new self($subjectId, 0, 0);
    }

    /**
     * Tek konuşma için imleç çifti — TEK sorgu.
     */
    public static function forConversation(int $conversationId, int $subjectId): self
    {
        return self::forConversations([$conversationId], $subjectId)[$conversationId]
            ?? self::empty($subjectId);
    }

    /**
     * Birden çok konuşma için imleç çiftleri — yine TEK sorgu (GROUP BY).
     * `GET /api/messages/search` sonuçları birden fazla konuşmaya yayılabildiği
     * için bu toplu biçim gerekir.
     *
     * @param  array<int, int>  $conversationIds
     * @return array<int, self> conversation_id => TickState
     */
    public static function forConversations(array $conversationIds, int $subjectId): array
    {
        $ids = array_values(array_unique(array_map('intval', $conversationIds)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('conversation_user')
            ->selectRaw('conversation_id, MAX(last_delivered_message_id) AS delivered_up_to, MAX(last_read_message_id) AS read_up_to')
            ->whereIn('conversation_id', $ids)
            ->where('user_id', '<>', $subjectId)
            ->groupBy('conversation_id')
            ->get();

        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row->conversation_id] = new self(
                $subjectId,
                (int) ($row->delivered_up_to ?? 0),
                (int) ($row->read_up_to ?? 0),
            );
        }

        return $states;
    }

    /**
     * Bir mesajın tik durumu.
     *
     * Gönderen `$subjectId` DEĞİLSE her zaman `sent` döner: tik göstergesi
     * yalnızca kendi mesajını gönderenin sorusudur ("karşı taraf gördü mü").
     * Başkasının mesajının yanında tik göstermek hem anlamsız hem de o kişinin
     * okuma alışkanlığını üçüncü bir kullanıcıya sızdırırdı.
     *
     * Okuma iletimi İMA EDER (`read` >= `delivered` her zaman doğru tutulur,
     * bkz. ChatReadState::markRead()), bu yüzden sıra önce `read`'dir.
     */
    public function for(Message $message): string
    {
        if ((int) $message->user_id !== $this->subjectId) {
            return self::SENT;
        }

        $id = (int) $message->getKey();

        if ($this->readUpTo >= $id) {
            return self::READ;
        }

        if ($this->deliveredUpTo >= $id) {
            return self::DELIVERED;
        }

        return self::SENT;
    }
}
