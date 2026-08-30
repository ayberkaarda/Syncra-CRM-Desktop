<?php

/*
|--------------------------------------------------------------------------
| Hata Mesajlari
|--------------------------------------------------------------------------
|
| Uygulamanin kendi hata cumleleri (yanit zarfindaki `errors.message`).
|
| Faz 14 / Iz D: `bootstrap/app.php`'deki tum sabit Turkce cumleler (kimlik/yetki/
| bulunamadi ucluesu + status `match` blogu + 5xx + dogrulama fallback'i), status-machine
| `denyTransition()` cumleleri (QuoteStatusMachine/TicketStatusMachine), UserDeactivated
| ve DealVersionConflictException buraya tasindi.
|
| `status_transition`/`quote_status`/`ticket_status` altindaki `:from`/`:to`/`:allowed`
| parametreleri durum MAKINE ADLARIDIR (draft/sent/open/...) - sabit degerler, kullanici
| verisi degil, ama yine de cumlenin DISINDA parametre olarak tasinir ki cumle cevrilebilsin.
*/

return [
    'unauthenticated' => 'Bu işlem için oturum açmanız gerekiyor.',
    'forbidden' => 'Bu işlem için yetkiniz yok.',
    'not_found' => 'Kayıt bulunamadı.',
    'method_not_allowed' => 'Bu adres için geçersiz istek yöntemi.',
    'session_expired' => 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.',
    'too_many_attempts' => 'Çok fazla deneme yapıldı. Lütfen bir süre sonra tekrar deneyin.',
    'request_failed' => 'İstek işlenemedi.',
    'server_error' => 'Beklenmeyen bir sunucu hatası oluştu.',
    'validation_failed' => 'Gönderilen bilgiler geçersiz.',

    'status_transition' => [
        'invalid' => 'Bu durum geçişine izin verilmiyor: :from → :to.',
    ],

    'quote_status' => [
        'send_endpoint_required' => 'Teklif yalnızca POST /api/quotes/{quote}/send ucundan gönderilebilir.',
        'terminal' => '":from" durumu terminaldir; bu teklifin durumu artık değiştirilemez. Değişiklik gerekiyorsa yeni bir teklif oluşturun.',
        'allowed_transitions' => '":from" durumundan yalnızca şunlara geçilebilir: :allowed.',
    ],

    'ticket_status' => [
        'terminal' => '":from" durumu terminaldir; bu ticket\'ın durumu artık değiştirilemez.',
        'allowed_transitions' => '":from" durumundan yalnızca şunlara geçilebilir: :allowed.',
    ],

    'deal_version_conflict' => [
        'message' => 'Bu kart siz sürüklerken başka bir kullanıcı tarafından güncellendi. '
            .'Panodaki güncel hâli aşağıda; işleminizi tekrar deneyin.',
    ],

    'user_deactivated' => [
        'message' => 'Hesabınız devre dışı bırakıldı. Oturumunuz sonlandırıldı.',
    ],

    // Faz 14 / İz F — C2 Kayıtlı Görünümler (docs/PHASE-AUDIT.md §5.4): `query_json`
    // sunucuda modül başına beyaz listeye karşı yeniden doğrulanır; bilinmeyen/izinsiz bir
    // alan gelirse sessizce atılmaz, açıkça reddedilir. `:fields` reddedilen alan
    // adlarıdır (ör. "filter.raw_sql, sort") — sabit teknik isimler, kullanıcı verisi değil.
    'saved_view' => [
        'invalid_query' => 'Kayıtlı görünüm sorgusu izin verilmeyen alan(lar) içeriyor: :fields.',
    ],
];
