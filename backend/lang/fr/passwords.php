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
    'reset' => 'Votre mot de passe a été réinitialisé.',
    'sent' => 'Nous avons envoyé le lien de réinitialisation de votre mot de passe.',
    'throttled' => 'Veuillez patienter avant de réessayer.',
    'token' => 'Ce jeton de réinitialisation du mot de passe est invalide.',
    'user' => 'Aucun utilisateur ne correspond à cette adresse e-mail.',
];
