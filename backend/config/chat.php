<?php

/*
|--------------------------------------------------------------------------
| Sohbet — dosya eki sınırları (Faz 12)
|--------------------------------------------------------------------------
|
| Tüm boyut/uzantı/MIME sabitleri TEK YERDE burada tanımlanır; controller,
| FormRequest ve servis bu dosyadan okur — hiçbir sınır ikinci bir yerde
| tekrarlanmaz (drift riski).
|
| GÜVENLİK: `mime_map` bir ALLOWLIST'tir (kara liste DEĞİL). Anahtarlar izin
| verilen dosya uzantıları, değerler bu uzantı için kabul edilen SUNUCU
| TARAFLI (dosya İÇERİĞİNDEN finfo ile tespit edilen — istemcinin gönderdiği
| Content-Type BAŞLIĞI DEĞİL) MIME türleri listesidir. Bir dosya hem uzantı
| hem de içerik olarak burada eşleşmezse reddedilir
| (bkz. App\Services\Attachments\AttachmentTypeGuard).
|
| SVG bilinçli olarak allowlist'te YOK: XML tabanlı, `<script>` ve olay
| dinleyicileri taşıyabilir; inline servis edilirse uygulama origin'inde
| çalışıp oturum çerezine erişebilir.
|
*/

$mimeMap = [
    // Belge
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword'],
    // Office Open XML (docx/xlsx/pptx) aslında bir ZIP konteyneridir; finfo
    // bazı ortamlarda bunu spesifik OOXML türü yerine `application/zip`
    // olarak tespit edebilir — bu yüzden ikisi de kabul listesindedir.
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ],
    'xls' => ['application/vnd.ms-excel'],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ],
    'ppt' => ['application/vnd.ms-powerpoint'],
    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
    ],
    'csv' => ['text/csv', 'text/plain'],
    'txt' => ['text/plain'],

    // Görsel (raster)
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],

    // Arşiv
    'zip' => ['application/zip', 'application/x-zip-compressed'],

    // Medya
    'mp4' => ['video/mp4'],
    'webm' => ['video/webm'],
    'mp3' => ['audio/mpeg'],
    'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
];

return [

    'attachments' => [

        /*
         * Maksimum dosya boyutu: 25 MB. Laravel'in `max` doğrulama kuralı
         * KB CİNSİNDENDİR — MB ile karıştırılmaz diye burada tek sayı
         * olarak (KB) hesaplanır (25 * 1024 = 25600).
         */
        'max_size_kb' => (int) env('CHAT_ATTACHMENT_MAX_KB', 25 * 1024),

        // Uzantı -> kabul edilen sunucu taraflı MIME türleri (allowlist).
        'mime_map' => $mimeMap,

        // Yalnızca okunabilirlik/FormRequest için: mime_map'in anahtarları.
        // Ayrı bir liste olarak elle tutulmaz — array_keys ile türetilir,
        // aksi halde iki liste zamanla birbirinden sapabilir (drift).
        'allowed_extensions' => array_keys($mimeMap),

        /*
         * `?inline=1` ile inline servis edilebilecek TEK grup: raster
         * görseller (chat önizlemesi). Başka HİÇBİR MIME inline servis
         * edilmez.
         */
        'inline_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],

        // storage/app/{disk}/{directory}/{uuid}.{ext} — public dışı disk.
        'disk' => env('CHAT_ATTACHMENT_DISK', 'local'),
        'directory' => 'attachments',

        /*
         * `attachments:prune-orphans` komutunun (öneri — routes/console.php'ye
         * KAYITLI DEĞİL, bkz. o dosyanın dokümanı) varsayılan saklama süresi:
         * bu süreden daha eski VE hâlâ hiçbir mesaja bağlanmamış
         * (`attachable_id IS NULL`) ekler silinmeye adaydır.
         */
        'orphan_retention_hours' => (int) env('CHAT_ATTACHMENT_ORPHAN_RETENTION_HOURS', 24),

    ],

];
