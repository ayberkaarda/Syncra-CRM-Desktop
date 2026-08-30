<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * H3/F2 — `.env.example` regresyon kilidi.
 *
 * `config/app.php`'deki `env('APP_DEBUG', false)` fallback'i zaten güvenli,
 * ama `.env.example` yeni bir geliştiricinin/deploy'un doğrudan kopyaladığı
 * şablon dosyasıdır: literal değer `true` olursa dikkatsiz bir kopyada
 * üretimde stack trace/secret sızıntısına yol açar. Bu test dosyanın
 * içeriğini okuyup satırın gerçekten `false` olduğunu doğrular — böylece
 * biri ileride yanlışlıkla `true`'ya çevirirse CI kırılır.
 *
 * `RefreshDatabase` KULLANILMAZ: bu test veritabanına dokunmaz, salt bir
 * metin dosyası okur.
 */
class EnvExampleTest extends TestCase
{
    public function test_env_example_ships_with_app_debug_false(): void
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertContains(
            'APP_DEBUG=false',
            $lines,
            '.env.example APP_DEBUG=false içermeli — aksi halde dikkatsiz bir kopya '.
            'üretimde debug modunu (stack trace sızıntısı) açık bırakır.'
        );

        $this->assertNotContains(
            'APP_DEBUG=true',
            $lines,
            '.env.example APP_DEBUG=true İÇERMEMELİ.'
        );
    }
}
