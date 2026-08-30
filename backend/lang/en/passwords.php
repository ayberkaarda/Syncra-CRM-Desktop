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
    'reset' => 'Your password has been reset.',
    'sent' => 'We have emailed your password reset link.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset token is invalid.',
    'user' => 'We cannot find a user with that email address.',
];
