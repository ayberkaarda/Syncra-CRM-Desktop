<?php

namespace App\Http\Resources\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\MorphTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * Konuşma gösterimi — Faz 12 uç sözleşmesi
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * `display_name` NEDEN SUNUCUDA HESAPLANIYOR
 * -----------------------------------------------------------------------------
 * Üç tipin başlığı üç farklı yerden gelir: `dm` KARŞI TARAFIN adı, `group`
 * konuşmanın kendi `name`'i, `record` ise bağlı kaydın etiketi ("FIR-2026-014
 * — Acme yenileme"). İstemcide çözülseydi her arayüz noktası (liste, başlık
 * çubuğu, bildirim, arama sonucu) aynı üç dallı mantığı yeniden yazardı ve
 * `record` dalı için ayrıca bir /deals veya /tickets isteği atması gerekirdi.
 * Tek bir alan, dört ekranı da tek doğruluk kaynağına bağlar.
 *
 * `dm` başlığı BAKAN KİŞİYE GÖRE değişir (aynı konuşma A'da "Berk", B'de
 * "Ayşe" görünür), bu yüzden Resource `$viewerId` olmadan doğru sonuç
 * üretemez — statik `payload()` bu yüzden alıcıyı açık parametre alır ve
 * `toArray()` onu istekten okur.
 *
 * -----------------------------------------------------------------------------
 * `unread_count` / `is_muted` PİVOTTAN, EK SORGU YOK
 * -----------------------------------------------------------------------------
 * İkisi de "bu konuşmanın" değil "bu konuşmanın BU KULLANICI için" halidir ve
 * `conversation_user` pivotunda yaşar. Zaten eager load edilmiş `users`
 * ilişkisinin pivotundan okunur; konuşma başına ayrı bir sorgu ATILMAZ.
 * Kullanıcı üye değilse (henüz açmamış bir `record` sohbeti) sırasıyla 0 ve
 * false döner — "hiç okunmamışım yok, susturmadım" doğru başlangıç hâlidir.
 *
 * -----------------------------------------------------------------------------
 * `last_message_preview` — GÖVDE DEĞİL ÖZET
 * -----------------------------------------------------------------------------
 * Liste satırı tam gövdeyi göstermez; 5.000 karakterlik bir mesajı listeye
 * taşımak boşuna bant genişliğidir. Kırpma sunucuda yapılır ki 40 konuşmalık
 * bir liste sabit boyutta kalsın. Silinmiş mesaj `null` döner (mezar taşının
 * içeriği maskelidir), dosya mesajı dosya adını, sistem mesajı kendi metnini
 * gösterir.
 *
 * @property-read Conversation $resource
 */
class ConversationResource extends JsonResource
{
    /**
     * Liste satırındaki önizlemenin karakter sınırı.
     */
    public const PREVIEW_LIMIT = 140;

    protected ?int $viewerId = null;

    /**
     * `$viewerId` AYRI bir bayrakla izlenir: "hiç verilmedi" ile "bilinçli
     * olarak null verildi" aynı şey DEĞİLDİR. Yayın gövdesi (bkz.
     * ConversationUpdated) kişiselleştirilemediği için kasıtlı olarak null
     * geçer; bayrak olmasaydı `toArray()` sessizce isteği yapan kullanıcıya
     * düşer ve o kişinin okunmamış sayısını kanaldaki HERKESE yayınlardı.
     */
    protected bool $viewerResolved = false;

    public function forViewer(?int $viewerId): static
    {
        $this->viewerId = $viewerId;
        $this->viewerResolved = true;

        return $this;
    }

    /**
     * @param  iterable<int, Conversation>  $conversations
     * @return array<int, self>
     */
    public static function manyForViewer(iterable $conversations, ?int $viewerId): array
    {
        $resources = [];

        foreach ($conversations as $conversation) {
            $resources[] = (new self($conversation))->forViewer($viewerId);
        }

        return $resources;
    }

    /**
     * Yayın gövdesi için düz dizi (bkz. MessageResource::payload() gerekçesi).
     *
     * @return array<string, mixed>
     */
    public static function payload(Conversation $conversation, ?int $viewerId): array
    {
        return (new self($conversation))->forViewer($viewerId)->toArray(request());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->resource;

        $viewerId = $this->viewerResolved ? $this->viewerId : $request->user()?->getKey();
        $viewerId = $viewerId === null ? null : (int) $viewerId;

        $members = $conversation->relationLoaded('users')
            ? $conversation->users
            : collect();

        $viewerPivot = $viewerId === null
            ? null
            : $members->firstWhere(fn (User $user): bool => (int) $user->getKey() === $viewerId)?->pivot;

        $conversableShort = MorphTargets::shortName($conversation->conversable_type);

        return [
            'id' => (int) $conversation->getKey(),
            'type' => $conversation->type,
            'name' => $conversation->name,
            'display_name' => $this->displayName($conversation, $members, $viewerId, $conversableShort),
            'conversable' => $conversableShort === null ? null : [
                'type' => $conversableShort,
                'id' => (int) $conversation->conversable_id,
                'label' => MorphTargets::label(
                    $conversableShort,
                    $conversation->relationLoaded('conversable') ? $conversation->conversable : null
                ),
            ],
            'created_by' => $conversation->created_by === null ? null : (int) $conversation->created_by,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->preview($conversation),
            'unread_count' => (int) ($viewerPivot->unread_count ?? 0),
            'is_muted' => (bool) ($viewerPivot->is_muted ?? false),
            'members' => $members
                ->map(fn (User $user): array => ChatUserResource::payload($user))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function displayName(
        Conversation $conversation,
        $members,
        ?int $viewerId,
        ?string $conversableShort,
    ): ?string {
        if ($conversation->isGroup()) {
            return $conversation->name;
        }

        if ($conversation->isRecord()) {
            return MorphTargets::label(
                $conversableShort,
                $conversation->relationLoaded('conversable') ? $conversation->conversable : null
            );
        }

        // dm — karşı taraf. Üyeler yüklenmemişse (ya da tek üye kalmışsa)
        // sessizce null döner; arayüz `name` alanına düşer.
        $other = $members->first(fn (User $user): bool => (int) $user->getKey() !== $viewerId);

        return $other?->name;
    }

    private function preview(Conversation $conversation): ?string
    {
        if (! $conversation->relationLoaded('latestMessage')) {
            return null;
        }

        /** @var Message|null $message */
        $message = $conversation->latestMessage;

        if ($message === null || $message->deleted_at !== null) {
            return null;
        }

        if ($message->type === Message::TYPE_FILE) {
            return $message->relationLoaded('attachment') && $message->attachment
                ? $message->attachment->original_name
                : $message->body;
        }

        return $message->body === null
            ? null
            : Str::limit($message->body, self::PREVIEW_LIMIT);
    }
}
