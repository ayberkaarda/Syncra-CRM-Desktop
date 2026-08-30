<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * App\Http\Middleware\SecurityHeaders — Faz 13 / H1.
 *
 * PHASE-AUDIT §6 kabul kriteri beş başlığın "her yanıtta" doğrulanmasını ister;
 * buradaki testler bunu üç eksende kilitler:
 *   1) yanıt SINIFI  — kimliksiz / kimlikli / 4xx / 5xx / HTML,
 *   2) CSP PROFİLİ   — JSON+ikili yanıt katı profili, HTML doküman profili,
 *   3) HSTS'in ŞEMAYA bağlı olması — düz HTTP'de gönderilmemesi ASIL kuraldır
 *      (bkz. SecurityHeaders::strictTransportSecurity gerekçesi).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * HSTS bilinçle DIŞARIDA: o başlık yalnız HTTPS isteklerde gelir ve kendi
     * testlerinde ayrıca doğrulanır.
     */
    private function assertBaselineHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
        $response->assertHeader('Content-Security-Policy');
    }

    private function csp(TestResponse $response): string
    {
        return (string) $response->headers->get('Content-Security-Policy');
    }

    public function test_unauthenticated_api_response_carries_the_security_headers(): void
    {
        // Kimlik doğrulaması gerektirmeyen yüzey: uç kimlikli olsa da yanıtı
        // (401) anonim bir istemci alır — başlıklar auth'tan ÖNCE garanti.
        $response = $this->getJson('/api/me')->assertStatus(401);

        $this->assertBaselineHeaders($response);
    }

    public function test_authenticated_api_response_carries_the_security_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me')->assertOk();

        $this->assertBaselineHeaders($response);
    }

    public function test_client_and_server_error_responses_also_carry_the_headers(): void
    {
        // Hata yanıtı da bir yanıttır: exception'dan render edilen gövde,
        // middleware pipeline'ından geri dönerken başlıkları almalı.
        Route::get('/api/__security_headers_boom', function () {
            throw new RuntimeException('patlat');
        });

        $this->assertBaselineHeaders($this->getJson('/api/yok-boyle-bir-uc')->assertStatus(404));
        $this->assertBaselineHeaders($this->getJson('/api/__security_headers_boom')->assertStatus(500));
    }

    public function test_api_responses_use_the_locked_down_csp_profile(): void
    {
        $csp = $this->csp($this->getJson('/api/me')->assertStatus(401));

        // JSON bir doküman değildir: hiçbir alt kaynağa izin vermek gerekmez.
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
        // Clickjacking koruması yanıt tipinden bağımsızdır.
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        // `sandbox` bilinçle yok: indirmeleri (export/ek) kırardı.
        $this->assertStringNotContainsString('sandbox', $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function test_html_responses_use_the_document_csp_profile(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertBaselineHeaders($response);

        $csp = $this->csp($response);

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        // Stil: <style>/stylesheet yüzeyi 'self' kalır, YALNIZCA inline style
        // ÖZNİTELİĞİ (React/Recharts) gevşetilir.
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $csp);

        // Fontlar self-host; hiçbir dış origin açılmaz.
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringNotContainsString('fonts.googleapis.com', $csp);

        // Reverb WS'i `'self'` KAPSAMAZ (farklı şema + port) — açıkça olmalı,
        // yoksa SPA Laravel'den servis edildiği gün realtime kırılır.
        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString(
            'ws://'.env('REVERB_HOST', 'localhost').':'.env('REVERB_PORT', 8080),
            $csp,
        );

        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function test_pdf_and_image_responses_use_the_media_csp_profile(): void
    {
        /*
         * Gerçek teklif PDF ucu yerine tipi taklit eden bir rota: burada
         * sınanan şey teklif iş mantığı değil, middleware'in İÇERİK TİPİNE göre
         * profil seçimi. Kural, Chrome'un inline PDF'i sentetik bir HTML
         * sayfasına gömüp ona inline stil enjekte etmesinden doğar —
         * `default-src 'none'` o görüntüleyiciyi kırar.
         */
        Route::get('/api/__security_headers_pdf', function () {
            return response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']);
        });

        Route::get('/api/__security_headers_png', function () {
            return response('', 200, ['Content-Type' => 'image/png']);
        });

        foreach (['/api/__security_headers_pdf', '/api/__security_headers_png'] as $uri) {
            $response = $this->get($uri)->assertOk();

            $this->assertBaselineHeaders($response);

            $csp = $this->csp($response);

            // Fetch direktifi YOK - görüntüleyici kırılmasın.
            $this->assertStringNotContainsString('default-src', $csp);
            $this->assertStringNotContainsString('object-src', $csp);
            $this->assertStringNotContainsString('style-src', $csp);

            // Asıl korumalar yerinde.
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("base-uri 'none'", $csp);
        }
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Geliştirme http://localhost üzerinde koşuyor. HSTS bir HOST kaydıdır:
        // localhost bir kez kilitlenirse Vite (5173) dahil tüm portlar HTTPS'e
        // zorlanır ve kayıt yalnız elle temizlenir.
        $this->get('http://localhost/api/me')
            ->assertStatus(401)
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/api/me')
            ->assertStatus(401)
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_a_route_that_sets_its_own_header_keeps_it(): void
    {
        // Middleware var olan başlığın üzerine yazmaz: bir ucun bilinçli
        // (ve muhtemelen daha katı) kararı sessizce ezilmemeli.
        Route::get('/api/__security_headers_own', function () {
            return response('ok')->header('X-Frame-Options', 'SAMEORIGIN');
        });

        $this->get('/api/__security_headers_own')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
