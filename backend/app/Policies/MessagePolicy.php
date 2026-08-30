<?php

namespace App\Policies;

use App\Http\Resources\UserResource;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * =============================================================================
 * MESAJ YETKİLERİ
 * =============================================================================
 *
 * Her karar ÖNCE konuşma görünürlüğünden geçer (ConversationPolicy::view) —
 * yani üyesi olmadığın bir konuşmadaki mesaj, var olmayan bir mesajla birebir
 * aynı yanıtı (404) verir. Mesaj id'leri global olarak artan bir dizidir;
 * 403/404 ayrımı sızsaydı, saldırgan yalnızca id tarayarak şirketin toplam
 * mesaj hacmini ve yoğun saatlerini çıkarabilirdi.
 *
 * -----------------------------------------------------------------------------
 * DÜZENLEME: YALNIZCA KENDİ METİN MESAJI, ZAMAN SINIRI YOK
 * -----------------------------------------------------------------------------
 * `type=file` mesaj düzenlenemez: düzenlenebilir olsaydı "gövde" değişir ama
 * karşı tarafın çoktan indirmiş olduğu DOSYA değişmezdi — arayüzde tutarlı
 * görünen, gerçekte tutarsız bir kayıt üretilirdi. `type=system` de
 * düzenlenemez, çünkü onu kullanıcı yazmamıştır.
 *
 * Zaman sınırı (ör. "5 dakika içinde") KASITLI olarak konmadı: bu kapalı devre
 * bir kurumsal sistemdir, sohbet bir kayıt ortamıdır ve üç gün önceki bir
 * yazım hatasını düzeltmek meşru bir ihtiyaçtır. Şeffaflığı `edited_at`
 * sağlar — düzenlenmiş her mesaj arayüzde işaretlidir.
 *
 * -----------------------------------------------------------------------------
 * SİLME: SAHİBİ + SUPER ADMIN — `settings.manage` DEĞİL
 * -----------------------------------------------------------------------------
 * Moderasyon yetkisi bilinçli olarak `settings.manage` iznine BAĞLANMADI. O
 * izin "şirket profilini, pipeline aşamalarını, e-posta şablonlarını
 * yönetebilir" demektir ve rol matrisinde Admin ile Satış Müdürü seviyesine
 * kadar iner. Ayarları düzenleyebilmek ile başkasının özel mesajını
 * silebilmek arasında hiçbir anlamlı bağ yoktur; ikisini aynı anahtara
 * bağlamak, ilk verildiğinde kimsenin fark etmediği sessiz bir yetki
 * genişlemesi olurdu.
 *
 * Yeni bir izin de AÇILMADI (63 izin sabit). Moderasyon, sistemin zaten var
 * olan EN ÜST yetkisine — `Super Admin` rolüne — bağlıdır. Bu rol
 * `AppServiceProvider::registerSuperAdminGate()` içindeki `Gate::before` ile
 * her yeteneği zaten kısa devre yapar; aşağıdaki AÇIK dal ise iki iş görür:
 * (1) kuralı policy'yi okuyan birine görünür kılar — görünmez bir Gate::before
 * yan etkisine bırakılmaz, (2) policy doğrudan çağrıldığında
 * (`$policy->delete(...)`, birim testleri) da doğru cevabı verir.
 */
class MessagePolicy
{
    public function view(User $user, Message $message): Response|bool
    {
        return $this->conversationVisible($user, $message);
    }

    public function update(User $user, Message $message): Response|bool
    {
        $visible = $this->conversationVisible($user, $message);

        if ($visible !== true) {
            return $visible;
        }

        if (! $this->isAuthor($user, $message)) {
            return Response::deny('Yalnızca kendi mesajınızı düzenleyebilirsiniz.');
        }

        if ($message->type !== Message::TYPE_TEXT) {
            return Response::deny('Yalnızca metin mesajları düzenlenebilir.');
        }

        if ($message->trashed()) {
            return Response::deny('Silinmiş bir mesaj düzenlenemez.');
        }

        return true;
    }

    public function delete(User $user, Message $message): Response|bool
    {
        $visible = $this->conversationVisible($user, $message);

        if ($visible !== true) {
            return $visible;
        }

        if ($this->isAuthor($user, $message)) {
            return true;
        }

        // Moderasyon dalı — gerekçe sınıf dokümanında.
        if ($user->hasRole(UserResource::SUPER_ADMIN_ROLE)) {
            return true;
        }

        return Response::deny('Yalnızca kendi mesajınızı silebilirsiniz.');
    }

    protected function isAuthor(User $user, Message $message): bool
    {
        return $message->user_id !== null
            && (int) $message->user_id === (int) $user->getKey();
    }

    /**
     * Mesajın konuşmasını görebiliyor muyum? Konuşma yoksa (kalıcı silinmiş)
     * yine 404 — bu da var olmayan bir mesajla aynı yanıttır.
     */
    protected function conversationVisible(User $user, Message $message): Response|bool
    {
        $conversation = $message->conversation;

        if ($conversation === null) {
            return Response::denyAsNotFound();
        }

        return app(ConversationPolicy::class)->view($user, $conversation);
    }
}
