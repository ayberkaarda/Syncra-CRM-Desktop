<?php

/*
|--------------------------------------------------------------------------
| Teklif PDF'i — statik etiketler (Faz 14 / İz D + İz E, ortak görev)
|--------------------------------------------------------------------------
|
| `resources/views/pdf/quote.blade.php` ve `App\Services\Quotes\
| QuotePdfService::addPageNumbers()` içinde kullanılır. PDF hangi dilde
| basılır kararı ve gerekçesi blade dosyasının başındaki docblock'tadır:
| ÖZETLE — talebi yapan/indiren kullanıcının o ANDAKİ `App::getLocale()`'i
| (SetLocale middleware'i `users.locale`'den zaten kurmuş olur).
|
| `status.*`: Quote::$status enum etiketleri — bu PDF'e özgü bir kopyadır
| (FE'nin `enums` namespace'inden AYRI ama AYNI anlam); belge sunucu
| tarafında üretildiği için FE sözlüğüne bağımlı olamaz.
|
*/

return [

    'quote_label' => 'Teklif',
    'title_label' => 'Başlık',
    'date_label' => 'Tarih',
    'validity_label' => 'Geçerlilik',
    'status_label' => 'Durum',

    'customer_info' => 'Müşteri Bilgileri',
    'phone_label' => 'Tel',
    'email_label' => 'E-posta',

    'items_section' => 'Teklif Kalemleri',
    'col_no' => '#',
    'col_description' => 'Açıklama',
    'col_quantity' => 'Miktar',
    'col_unit_price' => 'Birim Fiyat',
    'col_discount' => 'İndirim %',
    'col_tax' => 'KDV %',
    'col_amount' => 'Tutar',
    'unit_piece' => 'adet',

    'subtotal' => 'Ara Toplam',
    'discount' => 'İndirim',
    'tax' => 'KDV',
    'grand_total' => 'GENEL TOPLAM',

    'notes_section' => 'Notlar',
    'terms_section' => 'Şartlar ve Koşullar',

    // dompdf Canvas::page_text() `{PAGE_NUM}`/`{PAGE_COUNT}` belirteçlerini
    // kendisi doldurur — burada YALNIZCA çevredeki metin çevrilir.
    'page_indicator' => 'Sayfa :page / :total',

    // §2.3/§2.6: teklif `sent` anında donmuş kur satırı, ör.
    // "1 USD = 34,1234 TRY (24.08.2026)". TRY teklifte ve taslakta BASILMAZ
    // (bkz. blade'deki koşul).
    'exchange_rate_line' => '1 :currency = :rate :base (:date)',

    'status' => [
        'draft' => 'Taslak',
        'sent' => 'Gönderildi',
        'accepted' => 'Kabul Edildi',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi Doldu',
    ],

];
