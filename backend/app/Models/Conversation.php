<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sohbet — dm/group veya bir kayda (deal/ticket) gömülü olabilir.
 *
 * Üç tip TEK tabloda yaşar; ayrımı `type` kolonu yapar ve iş kuralları
 * App\Policies\ConversationPolicy ile App\Services\Chat\ConversationService
 * içinde uygulanır:
 *
 *   dm     tam 2 kişi, üye eklenmez/çıkarılmaz, silinmez; aynı iki kişi için
 *          ikinci bir dm AÇILAMAZ (get-or-create, bkz. ConversationService::
 *          findOrCreateDirect()).
 *   group  kurucu sahiptir; üye ekleme her üyeye, çıkarma/silme yalnızca
 *          kurucuya açıktır. Kurucu ayrılırsa sahiplik en eski üyeye devrolur.
 *   record `conversable` bir deal/ticket'tır; görünürlük o kaydın kendi
 *          `.view` iznine bağlıdır (presence-record.{type}.{id} kanalıyla
 *          AYNI kural) ve üyelik kaydı ilk açan kullanıcıya OTOMATİK verilir.
 */
class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_DM = 'dm';

    public const TYPE_GROUP = 'group';

    public const TYPE_RECORD = 'record';

    /**
     * @var array<int, string>
     */
    public const TYPES = [self::TYPE_DM, self::TYPE_GROUP, self::TYPE_RECORD];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'name',
        'conversable_type',
        'conversable_id',
        'created_by',
        'last_message_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Konuşma listesinin `last_message_preview` alanı — `with('latestMessage')`
     * ile eager load edildiğinde Laravel tek bir alt sorgu (`MAX(id)`) üretir,
     * yani N konuşma için N sorgu DEĞİL toplam iki sorgu çalışır.
     *
     * `latestOfMany('id')` (created_at değil): aynı saniye içinde gönderilmiş
     * iki mesajda `created_at` eşit olabilir ve hangisinin "son" olduğu
     * belirsizleşir; birincil anahtar monoton ve benzersizdir.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('last_read_message_id', 'last_delivered_message_id', 'unread_count', 'is_muted', 'joined_at')
            ->withTimestamps();
    }

    public function conversable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Yalnızca verilen kullanıcının üyesi olduğu konuşmalar.
     */
    public function scopeForMember(Builder $query, int $userId): Builder
    {
        return $query->whereHas('users', fn (Builder $users) => $users->whereKey($userId));
    }

    /**
     * Üyelik kontrolü. `users` ilişkisi zaten yüklüyse EK SORGU ATMAZ —
     * konuşma listesi ve Resource katmanı bu ilişkiyi her zaman eager load
     * ettiği için pratikte bellekten cevaplanır.
     */
    public function hasMember(int $userId): bool
    {
        if ($this->relationLoaded('users')) {
            return $this->users->contains(fn (User $user): bool => (int) $user->getKey() === $userId);
        }

        return $this->users()->whereKey($userId)->exists();
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DM;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isRecord(): bool
    {
        return $this->type === self::TYPE_RECORD;
    }
}
