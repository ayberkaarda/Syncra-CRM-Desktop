<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sohbet mesajı.
 *
 * -----------------------------------------------------------------------------
 * SİLME = MEZAR TAŞI (TOMBSTONE), SATIRIN YOK OLMASI DEĞİL
 * -----------------------------------------------------------------------------
 * Silinen mesaj listeden DÜŞMEZ: satır `deleted_at` ile yerinde kalır, yalnızca
 * içeriği (`body`, `attachment`) API katmanında null'a maskelenir (bkz.
 * App\Http\Resources\Chat\MessageResource). İki nedenle:
 *
 *   1. İmleçler (`last_read_message_id`, `last_delivered_message_id`) mesaj
 *      id'lerine dayanır; satır kaybolsa imleçler delik alır ve `before=`
 *      imleçli sayfalama boşluğa düşer.
 *   2. Sohbette bir mesajın izsiz kaybolması karşı tarafta "ben bunu okumuş
 *      muydum?" belirsizliği üretir. WhatsApp/Slack dahil her ürün burada
 *      mezar taşı gösterir.
 *
 * "Bu mesaj silindi" METNİNİ SUNUCU ÜRETMEZ — arayüz `deleted_at` dolu olan
 * mesajı kendi diliyle yazar (bkz. Faz 12 uç sözleşmesi).
 */
class Message extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_FILE = 'file';

    public const TYPE_SYSTEM = 'system';

    /**
     * @var array<int, string>
     */
    public const TYPES = [self::TYPE_TEXT, self::TYPE_FILE, self::TYPE_SYSTEM];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'attachment_id',
        'type',
        'edited_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
