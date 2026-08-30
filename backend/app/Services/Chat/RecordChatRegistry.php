<?php

namespace App\Services\Chat;

use App\Broadcasting\ChannelRegistry;
use App\Support\MorphTargets;
use Illuminate\Database\Eloquent\Model;

/**
 * `type=record` sohbetlerin GÖRÜNÜRLÜK sözlüğü.
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI BİR İZİN AÇILMADI
 * -----------------------------------------------------------------------------
 * Bir fırsatın altındaki sohbet, o fırsatın bir parçasıdır: kaydı görebilen
 * konuşmayı da görmeli, göremeyen duymamalı. Bunun için `chat.record.view`
 * gibi 64'üncü bir izin açmak, izin matrisini kaydın kendi izniyle SENKRON
 * tutma yükümlülüğü doğururdu (biri verilip diğeri unutulduğunda sessiz bir
 * sızıntı ya da sessiz bir kör nokta). 63 izin sabit kalır.
 *
 * -----------------------------------------------------------------------------
 * KURAL, `presence-record.{type}.{id}` KANALIYLA BİREBİR AYNI
 * -----------------------------------------------------------------------------
 * routes/channels.php'deki `record.{type}.{id}` callback'i üç adım uygular:
 * (1) beyaz liste — istemciden gelen `{type}` ASLA sınıf adına çevrilmez,
 * (2) ilgili modülün `.view` izni, (3) kaydın gerçekten var olması (yoksa id
 * uzayı sızar). Aynı üç adım App\Policies\ConversationPolicy içinde uygulanır
 * ve model + izin eşlemesi ikinci kez YAZILMAZ, ChannelRegistry::record()'dan
 * OKUNUR — iki yerin ayrışması, "kanalda duyabildiğim ama uçtan okuyamadığım
 * sohbet" (ya da tersi) gibi teşhisi zor bir tutarsızlık üretirdi.
 *
 * Kanal sözlüğü beş tip tanır (deal/ticket/contact/company/lead); sohbet
 * gömülü paneli yalnızca `deal` ve `ticket` detay sayfalarında bulunduğu için
 * burada ALT KÜME kullanılır (Faz 12 uç sözleşmesi: `conversable_type:
 * 'deal'|'ticket'`). Beyaz listeyi daraltmak güvenlik yönünde bir hatadır,
 * genişletmek değil.
 */
final class RecordChatRegistry
{
    /**
     * `POST /api/conversations/for-record` gövdesinde kabul edilen TEK tipler.
     *
     * @var array<int, string>
     */
    public const TYPES = ['deal', 'ticket'];

    /**
     * Kısa ad -> tam sınıf adı, yalnızca sohbete açık tipler için.
     *
     * @return class-string<Model>|null
     */
    public static function resolve(?string $shortName): ?string
    {
        if ($shortName === null || ! in_array($shortName, self::TYPES, true)) {
            return null;
        }

        return MorphTargets::resolve($shortName);
    }

    /**
     * Kaydı görüntülemek için gereken izin (`deals.view` / `tickets.view`).
     */
    public static function permission(?string $shortName): ?string
    {
        if ($shortName === null || ! in_array($shortName, self::TYPES, true)) {
            return null;
        }

        return ChannelRegistry::record($shortName)['permission'] ?? null;
    }

    /**
     * Kayıt gerçekten var mı? (Var olmayan bir id'ye sohbet açtırmak konuşma
     * tablosunda öksüz satır bırakır ve id uzayını sızdırır.)
     */
    public static function exists(?string $shortName, mixed $id): bool
    {
        $fqcn = self::resolve($shortName);

        if ($fqcn === null || ! is_numeric($id)) {
            return false;
        }

        return $fqcn::query()->whereKey((int) $id)->exists();
    }
}
