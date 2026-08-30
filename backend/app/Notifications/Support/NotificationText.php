<?php

namespace App\Notifications\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Bildirim METNİNİ okuma anında çözen tek yer — Faz 14 / İz D (docs/PHASE-INTL.md §1.4).
 *
 * ---------------------------------------------------------------------------
 * ÇÖZÜLEN SORUN: `notifications.data` DİL DONMASI
 * ---------------------------------------------------------------------------
 * Faz 10'da `CrmNotification::toDatabase()` RENDER EDİLMİŞ Türkçe `title`/`body`'yi DB'ye
 * yazıyordu. Metin gönderim anında dondu: kullanıcı sonradan dilini değiştirse bile geçmiş
 * bildirimleri sonsuza dek Türkçe kalırdı — ve bu, kimsenin fark etmeyeceği türden sessiz bir
 * bozulmadır (satır "doğru" görünür, yalnızca yanlış dildedir).
 *
 * YENİ SÖZLEŞME: `data` = `{ type, title_key, body_key, params, link, meta }`. Saklanan şey
 * ANLAM (anahtar + parametre); metin, OKUYANIN diliyle okuma anında üretilir. Bu, SLA'nın
 * "türetilmiş değer, bayrak değil" felsefesiyle (PROGRESS karar günlüğü) aynı hamledir.
 *
 * REDDEDİLEN ALTERNATİF: gönderim anında ALICININ `users.locale`'iyle render etmek. Alıcı
 * dilini sonradan değiştirdiğinde eski bildirimler yine yanlış dilde donardı — sorunu bir adım
 * ötelemek, çözmek değil.
 *
 * ---------------------------------------------------------------------------
 * GERİYE DÖNÜK UYUM (ZORUNLU)
 * ---------------------------------------------------------------------------
 * DB'de bu fazdan ÖNCE yazılmış satırlar düz `title`/`body` taşır ve GÖÇ EDİLMEZ: içlerinde
 * anlam değil, yalnızca cümle vardır — anahtar+parametreye geri çevrilemezler. `title_key`
 * yoksa saklanan düz metin OLDUĞU GİBİ basılır. Aynı yol, henüz dönüştürülmemiş bildirim
 * tiplerini de (11'den 9'u) çalışır tutar; dönüşüm bu sayede kademeli olabilir.
 */
final class NotificationText
{
    /**
     * `data` sütunundan görüntülenecek `title`/`body` çiftini üretir.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: ?string, body: ?string}
     */
    public static function resolve(array $data, string $locale): array
    {
        $titleKey = $data['title_key'] ?? null;

        // ESKİ SATIR / HENÜZ DÖNÜŞTÜRÜLMEMİŞ TİP: anahtar yok → saklanan düz metin.
        if (! is_string($titleKey) || $titleKey === '') {
            return [
                'title' => self::asNullableString($data['title'] ?? null),
                'body' => self::asNullableString($data['body'] ?? null),
            ];
        }

        $params = self::resolveParams($data['params'] ?? [], $locale);
        $bodyKey = $data['body_key'] ?? null;

        return [
            'title' => (string) __($titleKey, $params, $locale),
            'body' => is_string($bodyKey) && $bodyKey !== ''
                ? (string) __($bodyKey, $params, $locale)
                : null,
        ];
    }

    /**
     * PARAMETRE SÖZLEŞMESİ.
     *
     * `_at` ile BİTEN anahtarlar ISO-8601 tarih dizesi kabul edilir ve okuma anında okuyucunun
     * diliyle biçimlendirilir. GEREKÇE: tarih de en az cümle kadar dile bağlıdır ("24 Ağu 2026"
     * / "24 Aug 2026" / "24. Aug. 2026"); gönderim anında biçimlendirilseydi ay adı, cümle
     * çevrilmiş olsa bile Türkçe donardı — çözdüğümüz sorunun tam olarak küçük hâli.
     * Ad kuralı (sihirli bir tip alanı değil) bilinçli: `params` düz bir JSON haritasıdır,
     * içine tip bilgisi kaçırmak sözleşmeyi okunmaz yapardı.
     *
     * PARA TUTARI HENÜZ BU KURALIN DIŞINDA: gönderim anında biçimlendirilir (bkz.
     * `DealAssignedNotification`). Ayraç dile, simge/kod ise para birimine bağlı olduğu için
     * doğru çözüm okuma anında biçimlendirmektir — ama para birimi ekseninin sahibi İz E'dir
     * (PHASE-INTL §2), o yüzden burada bilinçli bir sınır bırakıldı: dil doğru, sayı ayracı
     * gönderim dilinde kalır.
     *
     * @return array<string, string>
     */
    private static function resolveParams(mixed $params, string $locale): array
    {
        if (! is_array($params)) {
            return [];
        }

        $resolved = [];

        foreach ($params as $key => $value) {
            $key = (string) $key;

            if (str_ends_with($key, '_at') && is_string($value) && $value !== '') {
                $resolved[$key] = self::formatDateTime($value, $locale);

                continue;
            }

            $resolved[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $resolved;
    }

    private static function formatDateTime(string $iso, string $locale): string
    {
        try {
            /** @var CarbonInterface $date */
            $date = Carbon::parse($iso);
        } catch (\Throwable) {
            // Bozuk bir tarih parametresi bildirimin TAMAMINI kaybettirmemeli; ham değer basılır.
            return $iso;
        }

        // `isoFormat` (Carbon'un yerelleştirilmiş biçimi) — `format()` DEĞİL: ikincisi ay
        // adlarını her zaman İngilizce basar.
        return $date->locale($locale)->isoFormat('D MMM YYYY HH:mm');
    }

    private static function asNullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
