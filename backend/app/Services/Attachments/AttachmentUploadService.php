<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Faz 12 — dosyayı diske yazar ve `attachments` satırını oluşturur.
 *
 * TABAN GÜVENLİK (Faz 13'ü beklemeden):
 *  - Diskteki ad RASTGELE (`Str::uuid()` + doğrulanmış uzantı). `original_name`
 *    hiçbir zaman disk yolunun bir parçası olarak kullanılmaz — kullanıcı
 *    adıyla diske yazmak path traversal / dosya adı enjeksiyonu açar.
 *  - `local` diski (storage/app/attachments/...) — public dışı, doğrudan
 *    URL ile erişilemez, yalnızca AttachmentController::show() üzerinden
 *    servis edilir.
 */
class AttachmentUploadService
{
    /**
     * StoreAttachmentRequest zaten AttachmentTypeGuard ile aynı kontrolü
     * yapmıştır; buradaki tekrar kontrol SAVUNMA KATMANI'dır — servis
     * ileride FormRequest doğrulaması olmadan başka bir yerden çağrılırsa
     * (ör. içe aktarma) sessizce yasak bir türü diske yazmaz.
     */
    public function store(UploadedFile $file, int $uploadedBy): Attachment
    {
        if (! AttachmentTypeGuard::isAllowed($file)) {
            throw new InvalidArgumentException('Dosya türü izin verilenler arasında değil ya da dosya içeriği uzantısıyla uyuşmuyor.');
        }

        $extension = AttachmentTypeGuard::extension($file);
        $filename = Str::uuid()->toString().'.'.$extension;

        $disk = (string) config('chat.attachments.disk', 'local');
        $directory = (string) config('chat.attachments.directory', 'attachments');

        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if ($path === false) {
            throw new InvalidArgumentException('Dosya diske yazılamadı.');
        }

        return Attachment::create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            // Doğrulanmış (sunucu taraflı tespit edilen) MIME — istemci
            // Content-Type başlığı DEĞİL.
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
