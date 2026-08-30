<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * AttachmentUploadService devri. Depolama/doğrulama mantığı burada değil,
 * App\Services\Attachments\* içinde.
 */
class AttachmentController extends Controller
{
    public function __construct(protected AttachmentUploadService $uploads) {}

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Attachment::class);

        $attachment = $this->uploads->store(
            $request->file('file'),
            (int) $request->user()->id,
        );

        return (new AttachmentResource($attachment))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * `GET /api/attachments/{attachment}[?inline=1]`.
     *
     * KİMLİK DOĞRULAMALI CONTROLLER DIŞINDA hiçbir yoldan servis edilmez —
     * disk `local` (public dışı), doğrudan URL ile erişilemez.
     *
     * Yetkisiz erişim 404 döner (403 DEĞİL — varlık sızdırma), bkz.
     * AttachmentPolicy sınıf üstü açıklaması.
     */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        if (Gate::denies('view', $attachment)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // İstisna: yalnızca raster görseller `?inline=1` ile inline servis
        // edilebilir (chat önizlemesi). Başka hiçbir tip inline EDİLMEZ —
        // `inline` isteği yoksayılır, indirme olarak servis edilir.
        $disposition = ($request->boolean('inline') && $attachment->isInlineEligibleImage()) ? 'inline' : 'attachment';

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                // Veritabanındaki DOĞRULANMIŞ mime_type — dosyadan yeniden
                // tahmin edilmez (bkz. AttachmentUploadService::store()).
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
    }
}
