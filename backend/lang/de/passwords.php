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
    'reset' => 'Ihr Passwort wurde zurückgesetzt.',
    'sent' => 'Wir haben Ihnen den Link zum Zurücksetzen des Passworts gesendet.',
    'throttled' => 'Bitte warten Sie, bevor Sie es erneut versuchen.',
    'token' => 'Dieser Token zum Zurücksetzen des Passworts ist ungültig.',
    'user' => 'Wir können keinen Benutzer mit dieser E-Mail-Adresse finden.',
];
