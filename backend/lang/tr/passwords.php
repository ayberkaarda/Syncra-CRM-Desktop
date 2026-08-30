<?php

/*
|--------------------------------------------------------------------------
| Sifre Sifirlama
|--------------------------------------------------------------------------
|
| Laravel'in sifre sifirlama anahtarlari. Bu sistemde kendi kendine sifirlama KAPALIDIR
| (talep yoneticiye iletilir, bkz. AUTH-FLOWS), ama cerceve bu dosyayi `Password` broker'i
| uzerinden cozebildigi icin anahtar kumesi eksiksiz tutulur.
*/

return [
    'reset' => 'Şifreniz sıfırlandı.',
    'sent' => 'Şifre sıfırlama bağlantısı gönderildi.',
    'throttled' => 'Lütfen tekrar denemeden önce bekleyin.',
    'token' => 'Şifre sıfırlama anahtarı geçersiz.',
    'user' => 'Bu e-posta adresine sahip bir kullanıcı bulunamadı.',
];
