<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Log Retention (gün)
    |--------------------------------------------------------------------------
    |
    | `logs:prune` komutunun --days ile ezilmediği sürece kullandığı varsayılan
    | saklama süreleri (ROADMAP R5). .env üzerinden ortam bazlı değiştirilebilir;
    | komut içinde hard-code edilmez.
    |
    */
    'log_retention' => [
        'page_visits' => (int) env('LOG_RETENTION_PAGE_VISITS_DAYS', 90),
        'sessions' => (int) env('LOG_RETENTION_SESSIONS_DAYS', 365),
        'activities' => (int) env('LOG_RETENTION_ACTIVITIES_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uluslararasılaştırma (Faz 14 / İz D — docs/PHASE-INTL.md §1.3, §1.4)
    |--------------------------------------------------------------------------
    |
    | `supported_locales` bir BEYAZ LİSTEDİR ve iki yerde tek doğruluk kaynağıdır:
    | `SetLocale` middleware'i ile `UpdatePreferencesRequest` doğrulaması. `users.locale`
    | istemciden gelen keyfi bir değer OLAMAZ — `App::setLocale()`'e denetimsiz bir dize
    | vermek, çeviri dosyası yolunu istemcinin etkisine açmak demektir.
    |
    | `default_locale` NEDEN `config('app.locale')` DEĞİL: `APP_LOCALE` Laravel'in kendi
    | çerçeve varsayılanıdır (kuyruk/konsol dahil her yerde geçerli) ve `en`de bırakılması,
    | henüz doldurulmamış `lang/tr/*` anahtarlarının çerçevenin İngilizce metinlerine
    | düşmesini sağlar (ham anahtar basmaz). Bu anahtar ise UYGULAMANIN kullanıcı-yüzeyli
    | varsayılanıdır — anonim/HTTP yanıtlarının dili. İkisi bilinçli olarak ayrıdır.
    |
    */
    'i18n' => [
        'supported_locales' => ['tr', 'en', 'de', 'fr'],
        'default_locale' => env('APP_DEFAULT_LOCALE', 'tr'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Para Birimi (PHASE-INTL §2.2 — İz E ile ORTAK)
    |--------------------------------------------------------------------------
    |
    | `users.preferred_currency` beyaz listesi. Kolon, dil tercihiyle AYNI göçte eklendi
    | (§2.3: ikisi de `users` tablosunda kişisel tercih), bu yüzden doğrulama listesi de
    | burada doğdu. İz E kur altyapısını kurarken bu listeyi genişletir/taşırsa `supported`
    | anahtarının ADI korunmalı — `UpdatePreferencesRequest` ona bağlı.
    |
    */
    'currency' => [
        'base' => 'TRY',
        'supported' => ['TRY', 'USD', 'EUR', 'GBP'],
    ],

];
