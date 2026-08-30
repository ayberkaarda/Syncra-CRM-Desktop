<?php

namespace App\Http\Resources\Chat;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sohbette bir kullanıcının GÖRÜNEN yüzü — sözleşme gereği tam üç alan.
 *
 * Faz 4'teki `ChannelRegistry::payload()` ile aynı ilke: bir sohbet listesi
 * hesap dökümü değildir. `is_active`, `must_change_password`, rol/izin listesi
 * ve `department` KASITLI olarak yoktur — mesajlaşan tarafın bilmesi gereken
 * tek şey kiminle konuştuğudur. (`email` sözleşmede var çünkü aynı ada sahip
 * iki kullanıcıyı ayırt etmenin tek yolu odur.)
 */
class ChatUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function payload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return self::payload($user) ?? [];
    }
}
