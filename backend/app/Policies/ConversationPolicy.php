<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\RecordChatRegistry;
use App\Support\MorphTargets;
use Illuminate\Auth\Access\Response;

/**
 * =============================================================================
 * SOHBET YETKİLERİ — İKİ AYRI SORU, İKİ AYRI CEVAP KODU
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * 403 vs 404: "YAPAMAZSIN" İLE "YOK" FARKI
 * -----------------------------------------------------------------------------
 * Bu policy iki farklı reddi bilinçli olarak farklı kodlarla üretir:
 *
 *   403  `chat.use` izni olmayan kullanıcı. Bu bir ÖZELLİK kapısıdır ve
 *        kullanıcı zaten sohbet modülünün var olduğunu bilir; "yetkin yok"
 *        demek hiçbir şey sızdırmaz ve doğru mesajdır.
 *   404  Üyesi OLMADIĞIN bir konuşma. Burada 403 dönmek, konuşmanın VAR
 *        OLDUĞUNU doğrulardı: saldırgan id'leri tarayıp 403 ile 404'ü
 *        ayırt ederek sistemdeki konuşma sayısını, hangi id aralığının dolu
 *        olduğunu ve dolaylı olarak şirket içi iletişim hacmini çıkarabilirdi
 *        (klasik IDOR/varlık sızıntısı). Bu yüzden `Response::denyAsNotFound()`
 *        kullanılır — bootstrap/app.php'deki hata zarfı bunu tekdüze
 *        `{"errors":{"message":"Kayıt bulunamadı.","code":"NOT_FOUND"}}`
 *        yanıtına çevirir ve var olmayan bir id ile birebir aynı görünür.
 *
 * -----------------------------------------------------------------------------
 * `type=record` GÖRÜNÜRLÜĞÜ — PİVOT DEĞİL, KAYDIN KENDİ İZNİ
 * -----------------------------------------------------------------------------
 * Bir fırsatın altındaki sohbete üyelik ÖNKOŞUL DEĞİLDİR: kaydı görebilen
 * herkes paneli açabilmeli (ve açtığı anda üye olur, bkz. ConversationService::
 * forRecord()). Kural, `presence-record.{type}.{id}` kanalının kuralıyla
 * BİREBİR aynıdır ve sözlük tek yerden okunur (RecordChatRegistry):
 * (1) beyaz listede olmayan tip reddedilir — istemciden gelen `{type}` asla
 * sınıf adına çevrilmez, (2) ilgili modülün `.view` izni aranır, (3) kaydın
 * gerçekten var olduğu doğrulanır.
 *
 * -----------------------------------------------------------------------------
 * SUPER ADMIN NOTU
 * -----------------------------------------------------------------------------
 * `AppServiceProvider::registerSuperAdminGate()` içindeki `Gate::before` Super
 * Admin için her yeteneği kısa devre yapar; dolayısıyla Super Admin bu
 * policy'ye HİÇ uğramaz ve üyesi olmadığı konuşmayı da görebilir. Bu bilinçli
 * bir sistem kararıdır (Faz 2) ve burada tekrar edilmez.
 */
class ConversationPolicy
{
    /**
     * Modül kapısı — listeleme ve oluşturma için yeterli.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('chat.use');
    }

    public function create(User $user): bool
    {
        return $user->can('chat.use');
    }

    public function view(User $user, Conversation $conversation): Response|bool
    {
        if (! $user->can('chat.use')) {
            return false;
        }

        if ($conversation->hasMember((int) $user->getKey())) {
            return true;
        }

        if ($conversation->isRecord() && $this->canSeeRecord($user, $conversation)) {
            return true;
        }

        return Response::denyAsNotFound();
    }

    /**
     * Grup adını değiştirme — yalnızca kurucu.
     */
    public function update(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        if (! $conversation->isGroup()) {
            return Response::deny('Yalnızca grup sohbetlerinin adı değiştirilebilir.');
        }

        return $this->isOwner($user, $conversation)
            ? true
            : Response::deny('Bu grubu yalnızca kurucusu düzenleyebilir.');
    }

    /**
     * Arşivleme (soft delete) — yalnızca kurucu, yalnızca grup.
     *
     * `dm` SİLİNEMEZ: iki kişilik bir sohbeti taraflardan biri yok edemez,
     * çünkü geçmiş ikisinin ORTAK kaydıdır. `record` de silinemez — o sohbet
     * kaydın bir parçasıdır ve kayıt durdukça durmalıdır.
     */
    public function delete(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        if (! $conversation->isGroup()) {
            return Response::deny('Yalnızca grup sohbetleri arşivlenebilir.');
        }

        return $this->isOwner($user, $conversation)
            ? true
            : Response::deny('Bu grubu yalnızca kurucusu arşivleyebilir.');
    }

    /**
     * Üye ekleme — konuşmanın HERHANGİ BİR üyesi.
     *
     * Kasıtlı olarak kurucuyla sınırlanmadı: bir çalışma grubuna doğru kişiyi
     * çağırmak günlük bir eylemdir ve her seferinde kurucuyu beklemek grubu
     * işlevsizleştirir. Yıkıcı olan taraf (çıkarma/silme) kurucuda kalır —
     * asimetri bilinçlidir.
     */
    public function addMember(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        if (! $conversation->isGroup()) {
            return Response::deny('Yalnızca grup sohbetlerine üye eklenebilir.');
        }

        return $conversation->hasMember((int) $user->getKey())
            ? true
            : Response::denyAsNotFound();
    }

    /**
     * Üye çıkarma — yalnızca kurucu.
     */
    public function removeMember(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        if (! $conversation->isGroup()) {
            return Response::deny('Yalnızca grup sohbetlerinden üye çıkarılabilir.');
        }

        return $this->isOwner($user, $conversation)
            ? true
            : Response::deny('Üyeleri yalnızca grubun kurucusu çıkarabilir.');
    }

    /**
     * Ayrılma — her üye kendisi için. `dm`'den ayrılmak YOKTUR: karşı tarafı
     * tek başına bırakılmış bir sohbetle baş başa bırakırdı.
     */
    public function leave(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        if ($conversation->isDirect()) {
            return Response::deny('Birebir sohbetten ayrılınamaz.');
        }

        return $conversation->hasMember((int) $user->getKey())
            ? true
            : Response::denyAsNotFound();
    }

    /**
     * Susturma ve imleç güncelleme — üyeliğe bağlı. `record` sohbetlerde
     * kaydı görebilen kullanıcı pivot satırını ilk açılışta zaten alır.
     */
    public function participate(User $user, Conversation $conversation): Response|bool
    {
        $visible = $this->view($user, $conversation);

        if ($visible !== true) {
            return $visible;
        }

        return $conversation->hasMember((int) $user->getKey())
            ? true
            : Response::denyAsNotFound();
    }

    /**
     * Mesaj yazma. `record` sohbetlerde üyelik henüz yoksa da izin verilir —
     * pivot satırı MessageService::create() içinde açılır.
     */
    public function sendMessage(User $user, Conversation $conversation): Response|bool
    {
        return $this->view($user, $conversation);
    }

    protected function isOwner(User $user, Conversation $conversation): bool
    {
        return $conversation->created_by !== null
            && (int) $conversation->created_by === (int) $user->getKey();
    }

    /**
     * `presence-record.{type}.{id}` ile AYNI üç adım.
     */
    protected function canSeeRecord(User $user, Conversation $conversation): bool
    {
        $short = MorphTargets::shortName($conversation->conversable_type);
        $permission = RecordChatRegistry::permission($short);

        if ($permission === null || ! $user->can($permission)) {
            return false;
        }

        return RecordChatRegistry::exists($short, $conversation->conversable_id);
    }
}
