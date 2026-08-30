<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * Faz 12 — allowlist + sunucu taraflı MIME doğrulama tek yerde.
 *
 * İstemcinin gönderdiği `Content-Type` (UploadedFile::getClientMimeType())
 * BİLİNÇLİ OLARAK KULLANILMAZ — kolayca sahtelenebilir. Bunun yerine
 * UploadedFile::getMimeType() kullanılır: Symfony bunu finfo_file() ile
 * dosyanın gerçek İÇERİĞİNDEN tespit eder (bkz.
 * vendor/symfony/http-foundation/File/File.php::getMimeType()).
 *
 * Çift uzantı (`fatura.pdf.exe`): getClientOriginalExtension() PHP'nin
 * pathinfo() davranışına göre SON uzantıyı döner ("exe"), bu da allowlist'te
 * doğal olarak yoktur — ayrıca bir kod yolu gerekmez.
 */
final class AttachmentTypeGuard
{
    /**
     * İstemcinin bildirdiği asıl dosya adından, KÜÇÜK HARFE çevrilmiş son
     * uzantıyı döner. `getClientOriginalExtension()` küçük harfe çevirmez,
     * bu yüzden burada normalize edilir (allowlist anahtarları hep küçük
     * harf).
     */
    public static function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
    }

    /**
     * Uzantı allowlist'te mi VE sunucu taraflı tespit edilen MIME bu
     * uzantı için beklenen türlerden biri mi — ikisi de doğrulanmadan
     * `true` dönmez.
     */
    public static function isAllowed(UploadedFile $file): bool
    {
        $extension = self::extension($file);

        /** @var array<string, array<int, string>> $mimeMap */
        $mimeMap = config('chat.attachments.mime_map', []);

        if (! array_key_exists($extension, $mimeMap)) {
            return false;
        }

        $detectedMimeType = $file->getMimeType();

        if ($detectedMimeType === null) {
            return false;
        }

        return in_array($detectedMimeType, $mimeMap[$extension], true);
    }
}
