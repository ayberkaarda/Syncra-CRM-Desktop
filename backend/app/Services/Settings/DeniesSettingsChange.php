<?php

namespace App\Services\Settings;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

/**
 * Ayarlar modülünün 422 yanıtları — tek yerden.
 *
 * =============================================================================
 * NEDEN İKİ YERE BİRDEN YAZILIYOR
 * =============================================================================
 * Projenin TEK hata zarfı bootstrap/app.php'de tanımlıdır:
 *
 *     { "errors": { "message": "...", "code": "...", "fields": { ... } } }
 *
 * Ayarlar ekranının bazı 422'leri ise yalnızca "hayır" demez, KARARI VERECEK
 * VERİYİ de taşır: "bu aşamada 7 açık fırsat var, şu aşamalara taşıyabilirsin"
 * (`STAGE_HAS_OPEN_DEALS`). Bu veri hata gövdesinin bir parçasıdır; ayrı bir
 * uçtan çekilseydi, kullanıcı diyaloğu görene kadar aradaki saniyelerde başka
 * biri aşamayı değiştirebilir ve seçenekler bayat olurdu.
 *
 * `code` ve ek alanlar bu yüzden HEM `errors` zarfının içine HEM de gövdenin
 * köküne yazılır. Kasıtlı bir tekrar:
 *
 *   - `errors.*` zarfı, uygulamadaki ORTAK hata işleyicisinin (toast, alan
 *     altı mesajlar) bu uçlarda da çalışmasını sağlar; ayarlar modülü için
 *     ikinci bir istisna yolu açılmaz.
 *   - Kökteki `code` / ek alanlar, Faz 10 sözleşmesinde frontend'e verilen
 *     biçimdir (`{ code, open_deals_count, available_stages }`) ve diyalog
 *     bileşeni onları zarfın içini bilmeden okur.
 *
 * Tekrar bir kez burada yazılır, çağıran taraflarda değil.
 */
trait DeniesSettingsChange
{
    /**
     * @param  array<string, array<int, string>>  $fields
     * @param  array<string, mixed>  $extra  hata gövdesine eklenen karar verisi
     *
     * @throws HttpResponseException 422
     */
    protected function deny(string $message, string $code, array $fields = [], array $extra = []): never
    {
        $envelope = ['message' => $message, 'code' => $code];

        if ($fields !== []) {
            $envelope['fields'] = $fields;
        }

        $body = ['errors' => array_merge($envelope, $extra), 'code' => $code];

        throw new HttpResponseException(
            response()->json(array_merge($body, $extra), Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
