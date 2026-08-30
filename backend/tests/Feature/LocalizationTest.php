<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 14 / İz D — dil çözümü (`SetLocale`), kişisel tercih ucu ve `lang/*` parite denetimi.
 *
 * Bu suite'in kilitlediği şey ÖNCELİK SIRASIDIR (docs/PHASE-INTL.md §1.3/§1.4):
 * `users.locale` → `Accept-Language` → uygulama varsayılanı. Sıra bozulursa hata sessizdir —
 * yanıt yine döner, yalnız yanlış dilde.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------
    // SetLocale — öncelik sırası
    // -------------------------------------------------------------------

    public function test_authenticated_user_locale_drives_the_response_language(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $this->assertSame('de', $response->headers->get('Content-Language'));
    }

    public function test_user_locale_wins_over_accept_language(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $response = $this->actingAs($user)
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->getJson('/api/me');

        $response->assertOk();
        // Kullanıcı arayüz dilini BİLİNÇLİ seçti; tarayıcısının dili onu ezmemeli.
        $this->assertSame('fr', $response->headers->get('Content-Language'));
    }

    public function test_accept_language_is_used_for_anonymous_responses(): void
    {
        $response = $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->getJson('/api/me');

        $response->assertStatus(401);
        $this->assertSame('You need to sign in to perform this action.', $response->json('errors.message'));

        // Middleware'in kimlik katmanının DIŞINDA durduğunun kanıtı: 401 bile başlığı taşır.
        // Bu sıra bozulursa (SetLocale `auth`in içine düşerse) anonim yanıtlar sessizce
        // uygulama varsayılanına döner — hata görünmez, yalnız dil yanlış olur.
        $this->assertSame('en', $response->headers->get('Content-Language'));
    }

    public function test_unsupported_accept_language_falls_back_to_the_application_default(): void
    {
        // `es` desteklenmiyor: Symfony'nin "eşleşme yoksa listenin ilk öğesi" davranışı
        // elenmeli ve karar uygulama varsayılanına (tr) düşmeli.
        $response = $this->withHeader('Accept-Language', 'es-ES,es;q=0.9')->getJson('/api/me');

        $response->assertStatus(401);
        $this->assertSame('Bu işlem için oturum açmanız gerekiyor.', $response->json('errors.message'));
    }

    public function test_empty_accept_language_falls_back_to_the_application_default(): void
    {
        /*
         * BOŞ başlık, "başlık yok" DEĞİL — ama test istemcisinden ulaşılabilen en yakın hâl:
         * Symfony'nin `Request::create()`'i her teste varsayılan bir `Accept-Language:
         * en-us,en;q=0.5` enjekte eder (ölçüldü), yani başlıksız istek simüle edilemez.
         * Boş değer de aynı kod yolunu (eşleşme yok → uygulama varsayılanı) sınar.
         */
        $response = $this->withHeader('Accept-Language', '')->getJson('/api/me');

        $response->assertStatus(401);
        $this->assertSame('Bu işlem için oturum açmanız gerekiyor.', $response->json('errors.message'));
    }

    public function test_german_accept_language_reaches_the_german_error_file(): void
    {
        $response = $this->withHeader('Accept-Language', 'de')->getJson('/api/me');

        $response->assertStatus(401);
        $this->assertSame('Für diese Aktion müssen Sie angemeldet sein.', $response->json('errors.message'));
    }

    public function test_error_envelope_is_translated_for_the_requesting_user(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        // `users.view` izni YOK → 403. Mesaj `lang/en/errors.php`'den gelmeli.
        $response = $this->actingAs($user)->getJson('/api/users');

        $response->assertStatus(403);
        $this->assertSame('You are not authorised to perform this action.', $response->json('errors.message'));
    }

    // -------------------------------------------------------------------
    // PATCH /api/me/preferences
    // -------------------------------------------------------------------

    public function test_user_can_update_their_own_locale_and_currency(): void
    {
        $user = User::factory()->create(['locale' => 'tr', 'preferred_currency' => 'TRY']);

        $response = $this->actingAs($user)->patchJson('/api/me/preferences', [
            'locale' => 'de',
            'preferred_currency' => 'EUR',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertJsonPath('data.preferred_currency', 'EUR');

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'locale' => 'de',
            'preferred_currency' => 'EUR',
        ]);
    }

    public function test_preferences_fields_are_independent(): void
    {
        $user = User::factory()->create(['locale' => 'tr', 'preferred_currency' => 'USD']);

        $this->actingAs($user)->patchJson('/api/me/preferences', ['locale' => 'fr'])->assertOk();

        // `sometimes` kuralı: gönderilmeyen alan SIFIRLANMAZ.
        $this->assertSame('USD', $user->fresh()->preferred_currency);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $user = User::factory()->create(['locale' => 'tr']);

        // `App::setLocale()`'e denetimsiz dize geçmek çeviri dosyası çözümünü istemcinin
        // etkisine açardı — beyaz liste bunu tek noktada keser.
        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['locale' => '../../etc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertSame('tr', $user->fresh()->locale);
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['preferred_currency' => 'XYZ'])
            ->assertStatus(422);
    }

    public function test_preferences_endpoint_requires_authentication(): void
    {
        $this->patchJson('/api/me/preferences', ['locale' => 'en'])->assertStatus(401);
    }

    public function test_preferences_endpoint_is_behind_the_forced_password_change_gate(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        // Muafiyet beyaz listesi bilinçli olarak dar: şifre değişimi ekranında dil seçimi
        // localStorage üzerinden zaten anında etkilidir, sunucuya yazma bekleyebilir.
        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['locale' => 'en'])
            ->assertStatus(403);
    }

    public function test_me_payload_exposes_the_personal_preferences(): void
    {
        $user = User::factory()->create(['locale' => 'fr', 'preferred_currency' => 'GBP']);

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.preferred_currency', 'GBP');
    }

    public function test_new_users_default_to_the_application_locale_and_base_currency(): void
    {
        $user = User::factory()->create();

        // Göç additive ve DEFAULT'lu; model varsayılanları da onunla hizalı olmak zorunda
        // (bkz. User::$attributes — hayalet audit diff'i).
        $this->assertSame('tr', $user->locale);
        $this->assertSame('TRY', $user->preferred_currency);
    }

    // -------------------------------------------------------------------
    // Anahtar paritesi (§1.7)
    // -------------------------------------------------------------------

    /**
     * `lang/{tr,en,de,fr}` anahtar kümeleri BİREBİR eşit olmalı.
     *
     * Eksik anahtar sessizce fallback dile düşer — yani hata görünmez, yalnızca metin yanlış
     * dilde çıkar. Bu tam olarak "sessizce bozulan" sınıfıdır ve ancak böyle bir denetimle
     * yakalanır. `validation.php` DIŞARIDA: o dosya bilinçli olarak boş bir iskelettir ve
     * çerçevenin kendi İngilizce kümesiyle birleşir (bkz. dosyanın başlığı).
     */
    public function test_backend_language_files_have_identical_key_sets(): void
    {
        $locales = (array) config('syncra.i18n.supported_locales');
        $groups = ['errors', 'auth', 'passwords', 'notifications'];

        foreach ($groups as $group) {
            $reference = null;

            foreach ($locales as $locale) {
                $path = lang_path("{$locale}/{$group}.php");
                $this->assertFileExists($path, "Eksik çeviri dosyası: lang/{$locale}/{$group}.php");

                $keys = $this->flattenKeys(require $path);
                sort($keys);

                if ($reference === null) {
                    $reference = $keys;

                    continue;
                }

                $this->assertSame(
                    $reference,
                    $keys,
                    "lang/{$locale}/{$group}.php anahtar kümesi diğer dillerle eşleşmiyor."
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<int, string>
     */
    private function flattenKeys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = array_merge($keys, is_array($value) ? $this->flattenKeys($value, $full) : [$full]);
        }

        return $keys;
    }
}
