<?php

namespace App\Services\Automation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Faz 14 / İz F — C4 başlık/mesaj şablonlarında SERBEST İFADE DEĞİL, yalnızca sabit bir
 * beyaz listeden placeholder'a izin verir (docs/PHASE-INTL.md §3: "Başlık şablonunda YALNIZ
 * sabit bir beyaz listeden placeholder'a izin ver — serbest ifade değerlendirmesi YOK").
 *
 * `{herhangi_bir_ad}` biçimindeki HER blok yakalanır; yakalanan ad beyaz listede değilse
 * reddedilir. Bu, `{deal.amount}`, `{7*7}` gibi bir ifade dili/enjeksiyon denemesini de
 * kapsar — parantez içindeki her şey birebir bir AD olmalı, nokta/operatör/boşluk YOK.
 */
final class AllowedPlaceholdersRule implements ValidationRule
{
    /**
     * @param  list<string>  $allowed
     */
    public function __construct(private readonly array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // `{`/`}` arasındaki HER ŞEYİ yakalar (yalnızca izinli ad deseniyle SINIRLI değil) —
        // böylece `{deal.amount}` veya `{7*7}` gibi beyaz listede olmayan bir içerik de
        // "eşleşmedi" diye sessizce görmezden gelinmez, açıkça YAKALANIP reddedilir.
        if (! preg_match_all('/\{([^{}]*)\}/', $value, $matches)) {
            return;
        }

        $unknown = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name): bool => ! in_array($name, $this->allowed, true)
        )));

        if ($unknown !== []) {
            $fail('validation.custom.automation.unknown_placeholder')->translate([
                'placeholders' => implode(', ', $unknown),
                'allowed' => implode(', ', $this->allowed),
            ]);
        }
    }
}
