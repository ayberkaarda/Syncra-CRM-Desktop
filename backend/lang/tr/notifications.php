<?php

/*
|--------------------------------------------------------------------------
| Bildirimler
|--------------------------------------------------------------------------
|
| BILDIRIM METINLERI - anahtar+parametre sozlesmesi (docs/PHASE-INTL.md 1.4).
|
| `notifications.data` artik render edilmis metin DEGIL, bu dosyanin anahtarlarini ve
| parametrelerini saklar; metin OKUMA aninda, OKUYANIN diliyle cozulur. Boylece kullanici
| dilini sonradan degistirdiginde gecmis bildirimler de yeni dilde okunur.
|
| Faz 14 / Iz D: 11 bildirim tipinin TAMAMI anahtar moduna donusturuldu.
|
| PARAMETRE SOZLESMESI: `:` ile baslayan yer tutucular `__()`'in kendi sozdizimidir.
| `_at` ile BITEN parametreler ISO-8601 tarih dizesidir ve okuma aninda okuyucunun
| diliyle bicimlendirilir (bkz. NotificationText::resolveParams()). Diger tum parametreler
| KULLANICI VERISIDIR (isim, tutar, sayi) ve OLDUGU GIBI basilir - onceden cevrilmis metin
| ASLA parametreye konmaz (koyulsaydi donusum yine dil donmasi olurdu). Bu yuzden ayni
| cumlenin varyantlari (aktor bilinen/bilinmeyen, grup/birebir, etiket var/yok, durum
| bazinda...) AYRI anahtar olarak durur - koşullu birlestirme degil.
*/

return [
    'deal_assigned' => [
        'title' => 'Size bir fırsat atandı',
        'body' => ':subject — :amount',
    ],
    'task_assigned' => [
        'title' => 'Size bir görev atandı',
        'body' => ':title',
        'body_with_due' => ':title — vade :due_at',
    ],
    'chat_mention' => [
        'title' => ':actor sizden bahsetti',
        'title_in_group' => ':actor sizden bahsetti (:conversation)',
        'title_unknown_actor' => 'Sizden bahsedildi',
        'title_unknown_actor_in_group' => 'Sizden bahsedildi (:conversation)',
        'body' => ':excerpt',
        'body_no_content' => 'Bir dosya paylaştı.',
    ],
    'deal_lost' => [
        'title' => 'Fırsat kaybedildi',
        'body' => ':subject — :amount',
    ],
    'deal_won' => [
        'title' => 'Fırsat kazanıldı',
        'body' => ':subject — :amount',
    ],
    'deal_stage_changed' => [
        'title' => 'Fırsat aşaması değişti',
        'body' => ':deal_title — artık ":stage" aşamasında',
    ],
    'lead_assigned' => [
        'title' => 'Size bir aday atandı',
        'body' => ':person',
        'body_with_company' => ':person — :company',
    ],
    'quote_status_changed' => [
        'title' => 'Teklif durumu değişti',
        'body_draft' => ':quote_number — Taslak',
        'body_sent' => ':quote_number — Gönderildi',
        'body_accepted' => ':quote_number — Kabul edildi',
        'body_rejected' => ':quote_number — Reddedildi',
        'body_expired' => ':quote_number — Süresi doldu',
        'body_default' => ':quote_number — :status',
    ],
    'task_reminder' => [
        'title' => 'Görev hatırlatması',
        'body' => ':title',
        'body_with_label' => ':title — :label',
    ],
    'ticket_assigned' => [
        'title' => 'Size bir destek talebi atandı',
        'body' => ':ticket_number — :subject',
    ],
    'ticket_sla_breached' => [
        'title' => 'SLA ihlal edildi',
        'body' => ':ticket_number — :subject (:minutes dk gecikme)',
    ],
    'ticket_sla_warning' => [
        'title' => 'SLA süresi azalıyor',
        'body' => ':ticket_number — :subject (kalan ~:minutes dk)',
    ],
];
