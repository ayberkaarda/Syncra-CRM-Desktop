<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * =============================================================================
 * `can` — YETKİ KARARINI SUNUCU SÖYLER, İSTEMCİ TEKRAR YAZMAZ
 * =============================================================================
 * Faz 13'te yazma eylemleri yatay sahiplik sınırına bağlandı (bkz.
 * App\Policies\Concerns\ChecksRecordOwnership). Bu kural artık "kullanıcının
 * izni var mı" kadar basit değil — "izni var VE sahip mi / kayıt sahipsiz mi /
 * `*.assign` taşıyor mu" bileşik bir sorudur. Arayüzün butonu gizlemek için
 * bu bileşimi KENDİ BAŞINA yeniden kurması, kuralın iki yerde yaşaması ve
 * ikisinin zamanla ayrışması demekti (arayüz butonu gösterir, API 403 döner —
 * ya da tersi, arayüz gizlerken kullanıcı aslında yetkilidir).
 *
 * Bu yüzden her kayıt, o kullanıcı için Policy'nin GERÇEK yanıtını yanında
 * taşır. Anahtar adları Policy metot adlarıyla birebir aynıdır; `can.status`
 * gibi ayrı bir uca karşılık gelen anahtarlar, o ucun controller'da hangi
 * yeteneği sorduğuna eşlenir.
 *
 * `can` YETKİLENDİRME DEĞİL, İPUCUDUR: tek yetki otoritesi Policy'dir ve
 * uçların her biri `Gate::authorize` çağırmaya devam eder.
 */
trait ExposesAbilities
{
    /**
     * @param  array<string, string>  $abilities  `çıktı anahtarı => policy yeteneği`
     * @return array<string, bool>
     */
    protected function abilities(Request $request, mixed $model, array $abilities): array
    {
        $user = $request->user();

        if ($user === null) {
            return array_fill_keys(array_keys($abilities), false);
        }

        return array_map(
            fn (string $ability): bool => $user->can($ability, $model),
            $abilities
        );
    }
}
