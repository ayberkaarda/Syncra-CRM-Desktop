<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * `chat.mention` — bir mesajda `mentions` dizisiyle işaretlenen kullanıcıya
 * (Faz 12).
 *
 * Faz 10'un `CrmNotification` taban sınıfı OLDUĞU GİBİ kullanılır: payload
 * sözleşmesi, `via()` sırası, `afterCommit()` ve `broadcastOn()` mantığı
 * orada bir kez çözülmüştür ve burada yeniden yazılmaz.
 *
 * FAZ 14 / İz D — ANAHTAR MODUNA DÖNÜŞTÜRÜLDÜ. Dört başlık varyantı vardır
 * (aktör bilinen/bilinmeyen × grup/birebir) çünkü "Bir kullanıcı" ("A user")
 * ve grup parantezi CÜMLENİN parçasıdır, ham parametre DEĞİL — çevirmenin
 * sözcük sırasını değiştirebilmesi için her varyant kendi tam cümlesiyle
 * sözlükte durmalı (bkz. TaskAssignedNotification'ın `body`/`body_with_due`
 * ayrımıyla aynı disiplin). `actor`/`conversation` parametreleri KULLANICI
 * VERİSİDİR (isim), çevrilmez.
 *
 * -----------------------------------------------------------------------------
 * `body` NEDEN MESAJIN KENDİSİ (KIRPILMIŞ)
 * -----------------------------------------------------------------------------
 * "Sizden bahsedildi" tek başına kullanıcıyı sohbeti açmaya zorlar. Bildirim
 * merkezinde mesajın ilk satırını görmek, çoğu durumda tıklamayı gereksiz
 * kılar. Kırpma sunucuda yapılır: `notifications.data` bir JSON kolonudur ve
 * 5.000 karakterlik bir mesajı oraya kopyalamak, bildirim listesini mesaj
 * tablosunun ikinci bir kopyasına dönüştürürdü.
 *
 * Dosya mesajlarında gövde boş olabilir; o durumda dosya adı parametre olarak
 * taşınır. İkisi de yoksa (excerpt null) `body_no_content` anahtarı — sabit
 * "Bir dosya paylaştı." cümlesi artık burada değil, sözlükte durur.
 */
class ChatMentionNotification extends CrmNotification
{
    /**
     * Bildirim gövdesindeki mesaj alıntısının karakter sınırı.
     */
    public const BODY_LIMIT = 160;

    public static function make(
        int $recipientId,
        Message $message,
        Conversation $conversation,
        ?User $actor,
    ): self {
        $inGroup = $conversation->isGroup() && $conversation->name !== null;
        $hasActor = $actor !== null;

        $titleKey = match (true) {
            $hasActor && $inGroup => 'notifications.chat_mention.title_in_group',
            $hasActor && ! $inGroup => 'notifications.chat_mention.title',
            ! $hasActor && $inGroup => 'notifications.chat_mention.title_unknown_actor_in_group',
            default => 'notifications.chat_mention.title_unknown_actor',
        };

        $excerpt = self::excerpt($message);

        [$bodyKey, $bodyParams] = $excerpt !== null
            ? ['notifications.chat_mention.body', ['excerpt' => $excerpt]]
            : ['notifications.chat_mention.body_no_content', []];

        return new self(
            recipientId: $recipientId,
            notificationType: 'chat.mention',
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            params: array_filter([
                'actor' => $hasActor ? (string) $actor->name : null,
                'conversation' => $inGroup ? (string) $conversation->name : null,
                ...$bodyParams,
            ], static fn (?string $value): bool => $value !== null),
            // Faz 12 arayüz yolu — sohbet ekranı konuşmayı id ile açar.
            notificationLink: '/chat/'.$conversation->getKey(),
            meta: [
                'conversation_id' => (int) $conversation->getKey(),
                'conversation_type' => $conversation->type,
                'message_id' => (int) $message->getKey(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }

    private static function excerpt(Message $message): ?string
    {
        $body = trim((string) $message->body);

        if ($body !== '') {
            return Str::limit($body, self::BODY_LIMIT);
        }

        if ($message->attachment !== null) {
            return (string) $message->attachment->original_name;
        }

        return null;
    }
}
