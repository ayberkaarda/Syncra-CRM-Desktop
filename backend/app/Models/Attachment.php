<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Ek dosya — public dizin dışında (storage/app/attachments) saklanır, yalnızca
// kimlik doğrulamalı AttachmentController::show() üzerinden servis edilir
// (bkz. Faz 12 / docs — imzalı URL Faz 13'ün konusu).
class Attachment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'filename',
        'original_name',
        'mime_type',
        'size',
        'disk',
        'path',
        'attachable_type',
        'attachable_id',
        'uploaded_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * `?inline=1` ile inline servis edilebilecek TEK grup: raster görseller
     * (chat önizlemesi). AttachmentController::show() ve AttachmentResource
     * aynı tanımı paylaşır — inline uygunluğu iki yerde ayrı ayrı
     * tanımlanmaz.
     */
    public function isInlineEligibleImage(): bool
    {
        return in_array($this->mime_type, config('chat.attachments.inline_mime_types', []), true);
    }

    /**
     * Polimorfik sahibi OLMAYAN ekler (`attachable_id IS NULL`) — yani
     * lead/contact zaman çizelgesine bağlanmamış olanlar.
     *
     * DİKKAT: bu scope "hiçbir yere bağlı değil" ANLAMINA GELMEZ. Bir eki
     * mesaja bağlayan yol `messages.attachment_id`'dir ve
     * `MessageService::create()` bu satırı yazarken `attachable_*`'a hiç
     * dokunmaz — sohbette gönderilmiş her ek bu scope'a DAHİLDİR. Silme
     * yapan bir sorguda tek başına kullanılırsa veri kaybettirir; bir kez
     * kaybettirdi de (bkz. App\Console\Commands\PruneOrphanAttachments
     * ::baseQuery(), `messages` alt sorgusunu neden eklediğini açıklar).
     */
    public function scopeUnattached(Builder $query): Builder
    {
        return $query->whereNull('attachable_id');
    }
}
