<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * =========================================================================
 * KRİTİK IDOR YÜZEYİ
 * =========================================================================
 *
 * `GET /api/attachments/{attachment}` — herhangi bir kimliği doğrulanmış
 * kullanıcı ardışık id'leri deneyerek başkasının dosyasını isteyebilir.
 * Bu Policy tek başına gerçek koruma katmanıdır:
 *
 *  - Ek henüz bir mesaja bağlanmamışsa (`attachable_id` NULL): yalnızca
 *    yükleyen (`uploaded_by`) erişebilir.
 *  - Bir mesaja bağlıysa: yalnızca o mesajın konuşmasının ÜYELERİ erişebilir
 *    — üyelik `conversation_user` pivotundan kontrol edilir (Conversation
 *    modelinin `users()` ilişkisiyle aynı tablo, doğrudan DB sorgusu N+1'i
 *    önler).
 *
 * AttachmentController::show() bu Policy'yi Gate::authorize() İLE DEĞİL
 * Gate::denies() ile çağırıp reddedileni 404'e çevirir — 403, isteğin
 * geçerli bir kayda ait olduğunu (varlık) sızdırır; burada istenen budur ki
 * sızdırılmasın.
 */
class AttachmentPolicy
{
    /**
     * `POST /api/attachments` — yükleme sohbet özelliğinin bir parçasıdır,
     * bu yüzden `chat.use` izniyle korunur (routes/api.php'de ayrıca
     * `password.changed` grubunun içinde).
     */
    public function create(User $user): bool
    {
        return $user->can('chat.use');
    }

    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->attachable_id === null) {
            return $attachment->uploaded_by !== null
                && (int) $attachment->uploaded_by === (int) $user->id;
        }

        // Bu fazda tek geçerli hedef Message'dır. Başka bir morph hedefi
        // (ör. ileride bir Deal notuna ek) tanıtılırsa, o modülün kendi
        // üyelik/erişim kuralı burada AÇIKÇA eklenmelidir — bilinmeyen bir
        // `attachable_type` sessizce reddedilir (fail-closed).
        if ($attachment->attachable_type !== Message::class) {
            return false;
        }

        /** @var Message|null $message */
        $message = $attachment->attachable;

        if ($message === null) {
            return false;
        }

        return DB::table('conversation_user')
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
