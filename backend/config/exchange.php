<?php

/*
|--------------------------------------------------------------------------
| Kur (döviz) altyapısı — Faz 14 / İz E (docs/PHASE-INTL.md §2)
|--------------------------------------------------------------------------
|
| DİKKAT: TCMB kaynak URL'i BİLİNÇLİ OLARAK burada YOK. `App\Services\
| Exchange\TcmbRateFetcher::SOURCE_URL` sabit bir PHP class sabitidir,
| env()/config() üzerinden OKUNMAZ — çekme hattının hedefi hiçbir ortam
| değişkeni veya kullanıcı girdisiyle değiştirilemez (SSRF yüzeyi yok,
| bkz. PHASE-INTL §2.5 "sabit URL sabiti, kullanıcı girdisi DEĞİL").
| Bu dosyadaki değerler yalnızca DAVRANIŞ ayarlarıdır (zaman aşımı,
| desteklenen para birimleri, eşikler) — hedef ADRESİ değil.
|
*/

return [

    /*
     * Temel (yerel) para birimi — her zaman TRY, tüm kurlar 1 birim
     * yabancı para için TRY karşılığıdır. TRY için exchange_rates'te
     * satır TUTULMAZ (bkz. ilgili migration'ın dokümanı); rate=1 örtük.
     */
    'base_currency' => 'TRY',

    /*
     * Desteklenen yabancı para birimleri (PHASE-INTL §2.2): tek şirket
     * TR merkezli (TRY temel), diller tr/en/de/fr olduğundan EUR (DE/FR),
     * GBP (İngiltere), USD (küresel ticaret + EN varsayılanı) seçildi.
     * Küçük ve denetlenebilir tutuldu; ileride eklenecek bir para birimi
     * (ör. Unit=100 olan JPY) yalnız bu listeye eklenir — çekme/bölme
     * mantığı zaten geneldir (bkz. TcmbRateFetcher).
     */
    'supported_currencies' => ['USD', 'EUR', 'GBP'],

    /*
     * Bayatlık eşiği (PHASE-INTL §2.6): bir kur bu eşikten fazla gündür
     * güncellenmemişse UI'da amber uyarı gösterilir. 4 gün = normal hafta
     * sonu (Cuma kuru Pazartesi sabahına dek geçerli sayılır, 3 gün) + 1
     * resmi tatil toleransı.
     */
    'stale_threshold_days' => 4,

    /*
     * Manuel kur girişi (Ayarlar — başka şeridin FE/BE ucu) için üst
     * sınır: fahiş yanlış girişi (ör. 3211.50 yerine 32115.0) yakalamak
     * içindir, gerçek piyasa senaryosu değildir. TCMB kurlarının tarihsel
     * olarak bu bandın çok altında kaldığı gözlemiyle geniş bırakıldı —
     * amaç makul-aralık kontrolü, döviz tahmini değil.
     */
    'manual_rate_max' => 100000,

    /*
     * Giden HTTP çağrısı sertleştirmesi (H7, PHASE-INTL §2.5):
     * TLS doğrulaması KAPATILMAZ (Laravel Http varsayılanı zaten açık,
     * burada devre dışı bırakan hiçbir kod yoktur).
     */
    'fetch' => [
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 5,
        'retry_times' => 2,
        'retry_delay_ms' => 500,
        // Ayrıştırmadan ÖNCE uygulanan gövde boyut sınırı. TCMB günlük
        // XML'i tipik olarak ~30-60 KB'tır; 5 MB aşırı büyük/kötü niyetli
        // yanıtı ayrıştırmaya hiç girmeden reddetmek için geniş bir tampon.
        'max_response_bytes' => 5 * 1024 * 1024,
    ],

];
