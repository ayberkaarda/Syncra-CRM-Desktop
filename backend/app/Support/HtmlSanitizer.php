<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;

/**
 * Faz 13 / H6 (§4-F5, §2-A5.3) — kullanıcı tarafından yazılan HTML'in
 * BEYAZ LİSTELİ sanitizasyonu. Bugünkü tek çağıran e-posta şablonu
 * `body_html` alanı (`StoreEmailTemplateRequest`, `UpdateEmailTemplateRequest`,
 * `EmailTemplateService`).
 *
 * =============================================================================
 * KARAR 1 — MOTOR: kendi çözümümüz DEĞİL, HTMLPurifier
 * =============================================================================
 * Repoda `ezyang/htmlpurifier` 4.19 ZATEN KURULU (phpspreadsheet üzerinden
 * geliyor; `composer why ezyang/htmlpurifier`). XSS sanitizasyonu, elle
 * yazılmış bir DOMDocument gezgininin kolayca kaçırdığı bir sınıf saldırıya
 * açıktır: mutation-XSS (tarayıcının yeniden ayrıştırmasıyla ortaya çıkan
 * yükler), koşullu yorumlar, `<noscript>`/`<template>` içerik bağlamları,
 * eksik kapatılmış etiketlerin ayrıştırıcıya göre değişen ağaç üretmesi.
 * HTMLPurifier tam da bunun için yazılmış ve yıllardır saldırıya maruz kalmış
 * bir kütüphanedir; hazır ve denenmiş bir motor dururken güvenlik-kritik bir
 * ayrıştırıcıyı yeniden yazmak net bir kayıptır.
 *
 * BİLİNEN KIRILGANLIK ve NEDEN BÖYLE BIRAKILDI: paket `composer.json`'da
 * DOĞRUDAN bağımlılık olarak yazılı değil, `phpoffice/phpspreadsheet`'in
 * geçişli bağımlılığı. Bu turda bağımlılık eklemek yasak olduğu için
 * `composer.json` DEĞİŞTİRİLMEDİ. Bunun yerine iki koruma kondu:
 *   1. Aşağıdaki `purifier()` sınıf yoksa SESSİZCE GEÇMEZ — `RuntimeException`
 *      atar (fail-closed). Sanitize edilememiş HTML asla kayıt yoluna girmez.
 *   2. `tests/Unit/HtmlSanitizerTest::test_html_purifier_dependency_is_present`
 *      bağımlılığın varlığını sabitler; ileride bir `composer update`
 *      phpspreadsheet'i değiştirip paketi düşürürse CI GÜRÜLTÜLÜ patlar,
 *      üretimde sessizce kaybolmaz.
 * YAPILACAK (bağımlılık eklemenin serbest olduğu ilk tur): `composer require
 * ezyang/htmlpurifier` ile doğrudan bağımlılığa çevir.
 *
 * =============================================================================
 * KARAR 2 — `style`/`class`/`id` YOK
 * =============================================================================
 * E-posta HTML'i pratikte satır içi CSS'e dayanır, dolayısıyla bu ölçülü bir
 * İŞLEVSELLİK KAYBIDIR ve bilinçlidir. Gerekçe: satır içi CSS bağımsız bir
 * saldırı yüzeyidir (`url(javascript:)`, eski `expression()`, `-moz-binding`
 * ve en önemlisi `background-image:url(https://saldirgan/…)` ile şablonu
 * görüntüleyen herkesin IP/istek izinin sızması). Bunu doğru filtrelemek bir
 * CSS ayrıştırıcısı ister. Bu fazda E-POSTA GÖNDERİLMİYOR (`MAIL_MAILER=log`,
 * bkz. EmailTemplateService) — yani stil kaybının bugün gerçek bir bedeli yok.
 * Beyaz listeyi DAR başlatıp sonra genişletmek, geniş başlatıp daraltmaktan
 * her zaman güvenlidir: genişletme geriye dönük veriyi bozmaz, daraltma bozar.
 * `id` ayrıca kapatıldı (`Attr.EnableID=false`): sayfadaki gerçek `id`'lerle
 * çakışıp DOM clobbering yüzeyi açar.
 *
 * =============================================================================
 * KARAR 3 — ŞEMA BEYAZ LİSTESİ: http, https, mailto
 * =============================================================================
 * `javascript:`, `vbscript:`, `data:text/html` REDDEDİLİR. Kaçırma
 * vektörleri (`JaVaScRiPt:`, `java&#9;script:`, öndeki boşluk/kontrol
 * karakterleri) REGEX'LE DEĞİL, AYRIŞTIRMA SIRASIYLA yenilir: HTMLPurifier
 * öznitelik değerini önce HTML varlık çözümlemesinden geçirir, ardından URI
 * olarak ayrıştırıp şemayı normalize eder — `java&#9;script:` DOM'da
 * `java<TAB>script:` olur, geçerli bir şema üretmez ve zararsız göreli URL'e
 * yüzde-kodlanır. `data:` BİLEREK dışarıda: `data:image/svg+xml` içine script
 * gömülebildiği için "sadece resim" ayrımı görünenden incedir; gömülü resim
 * ihtiyacı doğarsa ayrı bir kararla eklenir.
 *
 * GÖRELİ URL'ler (`/logo.png`, `sayfa.html`) SERBEST bırakılır: şemasız bir
 * değer tanımı gereği `javascript:` olamaz, dolayısıyla güvenlik sorunu
 * değildir; yasaklamak yalnız meşru içeriği bozardı.
 *
 * =============================================================================
 * KARAR 4 — TANIM ÖNBELLEĞİ KAPALI, ÖRNEK BELLEKTE TUTULUYOR
 * =============================================================================
 * `Cache.DefinitionImpl=null`: HTMLPurifier varsayılan olarak HTML tanımını
 * diske serileştirir; bu, yazılabilir bir dizin ve ona bağlı yeni bir hata
 * modu demektir. Tanım kurulumu pahalıdır ama sanitize edilen şey seyrek bir
 * yönetici işlemidir (şablon kaydı) — bunun yerine örnek süreç içinde
 * `self::$purifier` ile bir kez kurulur, aynı istekteki ikinci çağrı
 * (FormRequest + Service çift katmanı) bedavaya gelir.
 */
final class HtmlSanitizer
{
    /**
     * İzinli etiket => izinli öznitelikleri. Boş dizi "etiket serbest, hiçbir
     * özniteliği değil" demektir.
     *
     * Kapsam gerekçesi: bir e-posta gövdesinin gerçekten ihtiyaç duyduğu
     * asgari küme — metin akışı, vurgu, liste, başlık, bağlantı, resim ve
     * tablo (e-posta düzeni hâlâ tabloyla kurulur). `h5`/`h6` dışarıda:
     * e-posta gövdesinde altı seviye başlık hiyerarşisinin karşılığı yok.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'a' => ['href', 'title'],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        'span' => [],
        'div' => [],
        'img' => ['src', 'alt', 'width', 'height'],
        'blockquote' => [],
        'hr' => [],
    ];

    /**
     * `href`/`src` için izinli şemalar (bkz. KARAR 3).
     *
     * @var array<int, string>
     */
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private static ?HTMLPurifier $purifier = null;

    /**
     * E-posta şablonu gövdesini beyaz listeye göre temizler.
     *
     * İDEMPOTENT olmak ZORUNDA: aynı değer hem FormRequest'te (doğrulamadan
     * önce) hem serviste (kayıttan önce) geçiyor; ikinci geçiş içeriği
     * değiştirirse şablonlar her kaydedişte aşınırdı. Testle sabitlendi.
     */
    public static function sanitizeEmailBody(string $html): string
    {
        // Yalnız boşluk: HTMLPurifier'ı hiç kurmadan çık (tanım kurulumu pahalı).
        if (trim($html) === '') {
            return '';
        }

        return self::purifier()->purify($html);
    }

    /**
     * Sanitizasyon motoru yüklü mü. Yalnız tanı/test içindir — çağrı
     * yollarında KULLANILMAZ: bir çağıranın "yoksa temizlemeden geç"
     * seçeneği olmamalı (bkz. KARAR 1, fail-closed).
     */
    public static function isAvailable(): bool
    {
        return class_exists(HTMLPurifier::class);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        if (! self::isAvailable()) {
            // FAIL-CLOSED: temizlenememiş HTML'i geçirmektense isteği
            // patlatmak yeğdir. Sessizce `strip_tags`'e düşmek de yanlış
            // olurdu — yöneticinin şablonu geri dönülmez biçimde düz metne
            // iner ve nedeni hiçbir yerde görünmez.
            throw new RuntimeException(
                'HTML sanitizasyon motoru (ezyang/htmlpurifier) yüklü değil; '
                .'sanitize edilmemiş HTML kaydedilemez. Paketi composer.json içine '
                .'DOĞRUDAN bağımlılık olarak ekleyin (bkz. HtmlSanitizer KARAR 1).'
            );
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::allowedSpec());
        $config->set('URI.AllowedSchemes', array_fill_keys(self::ALLOWED_SCHEMES, true));
        $config->set('Attr.EnableID', false);
        $config->set('Cache.DefinitionImpl', null);

        return self::$purifier = new HTMLPurifier($config);
    }

    /**
     * `ALLOWED` dizisini HTMLPurifier'ın `HTML.Allowed` biçimine çevirir
     * (`a[href|title],p,img[src|alt]`). Beyaz liste tek yerde (yapısal dizi)
     * dursun diye TÜRETİLİYOR — iki yerde tutulan bir güvenlik listesi
     * kaçınılmaz olarak ayrışır.
     */
    private static function allowedSpec(): string
    {
        $parts = [];

        foreach (self::ALLOWED as $tag => $attributes) {
            $parts[] = $attributes === [] ? $tag : $tag.'['.implode('|', $attributes).']';
        }

        return implode(',', $parts);
    }
}
