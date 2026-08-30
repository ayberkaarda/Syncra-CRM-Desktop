<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * BAHSETME (@mention) — İSTEMCİ `mentions: [user_id]` GÖNDERİR,
 * SUNUCU METİN AYRIŞTIRMAZ
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * KARAR VE GEREKÇESİ
 * -----------------------------------------------------------------------------
 * Serbest metinde `@Ad Soyad` yakalamak ÇÖZÜLEMEZ bir belirsizlik taşır ve
 * bunun üç somut biçimi vardır:
 *
 *   1. SINIR PROBLEMİ. `@Ali Veli Bey nasılsınız` içinde isim nerede biter?
 *      "Ali", "Ali Veli" ve "Ali Veli Bey" üçü de dilbilgisel olarak geçerli
 *      adaydır. Türkçede iki ve üç kelimelik adlar yaygın olduğu için hiçbir
 *      açgözlü/cimri eşleme kuralı doğru sonucu garanti etmez.
 *   2. ÇAKIŞMA PROBLEMİ. Şirkette iki "Mehmet Yılmaz" varsa metin hangisini
 *      kastettiğini TAŞIMAZ. Sunucu ya rastgele birini seçer (yanlış kişiye
 *      bildirim) ya da ikisine birden gönderir (gürültü).
 *   3. SESSİZ BAŞARISIZLIK. Kullanıcı `@ayberk` yazar ama sistemde ad
 *      "Ayberk Arda"dır: eşleşme olmaz, bildirim gitmez ve kullanıcı bunu
 *      ASLA öğrenmez — bahsettiğini sanıp yanıt bekler. Bu, hiç bahsetmemekten
 *      daha kötüdür.
 *
 * İstemci tarafında ise belirsizlik hiç doğmaz: kullanıcı `@` yazdığında
 * açılan listeden BİR KİŞİ SEÇER; o an hangi kullanıcı id'sinin kastedildiği
 * kesin olarak bilinir. Bu yüzden sözleşme şudur:
 *
 *   - `body` görüntülenecek metni taşır ve içinde `@Ad Soyad` GEÇEBİLİR —
 *     sunucu bu metni okumaz, ayrıştırmaz, doğrulamaz. Metin sadece metindir.
 *   - `mentions` mesajla birlikte gönderilen bir KULLANICI ID DİZİSİDİR ve
 *     bildirimin TEK kaynağıdır.
 *
 * Yan fayda: kullanıcı mesajı düzenleyip `@Ali`yi silse bile geçmişte gitmiş
 * bildirim tutarlı kalır ve sunucu "artık bahsedilmiyor" gibi geri alınamaz
 * bir durumu takip etmek zorunda kalmaz.
 *
 * -----------------------------------------------------------------------------
 * KİMLER ELENİR
 * -----------------------------------------------------------------------------
 *   - Konuşmanın ÜYESİ OLMAYANLAR. Aksi halde `mentions` dizisi, kullanıcının
 *     hiç göremeyeceği bir sohbetin içeriğini (bildirim gövdesinde alıntı
 *     olarak) ona ulaştıran bir sızıntı kanalına dönüşürdü. Eleme SESSİZDİR
 *     (422 değil): istemci ile sunucu arasında üyelik yarışı olabilir ve
 *     mesajın kendisi bu yüzden reddedilmemelidir.
 *   - `is_muted = true` olan üyeler. Susturma bildirim tercihidir.
 *   - GÖNDERENİN KENDİSİ. Kendine bildirim üretilmez — kuralın uygulandığı yer
 *     zaten `NotificationDispatcher::send()`'dir, burada ayrıca elenmesi
 *     gereksiz bir sorgu turunu (`User::find`) engeller.
 */
final class MentionResolver
{
    /**
     * Bildirim üretilecek alıcıları döner.
     *
     * @param  array<int, int|string>  $mentionedUserIds  istemciden gelen ham dizi
     * @return Collection<int, User>
     */
    public function recipients(Conversation $conversation, array $mentionedUserIds, int $senderId)
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $mentionedUserIds),
            fn (int $id): bool => $id > 0 && $id !== $senderId,
        )));

        if ($ids === []) {
            return collect();
        }

        // Üyelik + susturma kontrolü TEK sorguda: pivot satırı olmayan ya da
        // susturmuş olan id'ler burada düşer.
        $eligibleIds = DB::table('conversation_user')
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('user_id', $ids)
            ->where('is_muted', false)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($eligibleIds === []) {
            return collect();
        }

        return User::query()->whereKey($eligibleIds)->get();
    }
}
