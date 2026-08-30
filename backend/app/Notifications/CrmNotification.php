<?php

namespace App\Notifications;

use App\Notifications\Support\NotificationText;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Faz 10 payload sözleşmesindeki 5 anahtarı ({@see self::toArray()}) tüm 11
 * bildirim tipi için TEK yerde üretir; her alt sınıf yalnızca kendi
 * title/body/link/meta'sını hesaplar.
 *
 * ---------------------------------------------------------------------------
 * `broadcastOn()` NEDEN `$recipientId`'Yİ CONSTRUCTOR'DAN ALIYOR
 * ---------------------------------------------------------------------------
 * `Illuminate\Notifications\Events\BroadcastNotificationCreated::broadcastOn()`
 * (vendor kaynağı okunarak doğrulandı) alıcıyı (`$notifiable`) DEĞİL, doğrudan
 * `$this->notification->broadcastOn()`'u parametresiz çağırır — yani bu
 * metodun `$notifiable`'a erişimi YOKTUR. Kanal adını üretmek için alıcının
 * id'si dispatch anında (constructor'da) yakalanıp saklanır. `toDatabase()` /
 * `toBroadcast()` ise `$notifiable` parametresini gerçekten alır (Laravel
 * kanal sözleşmesi), o yüzden onlarda ayrıca saklamaya gerek yok.
 *
 * ---------------------------------------------------------------------------
 * `afterCommit()` NEDEN CONSTRUCTOR'DA ZORUNLU
 * ---------------------------------------------------------------------------
 * Bu bildirimler `ShouldQueue` + `QUEUE_CONNECTION=redis`; tetikleyen
 * observer/listener çoğu zaman bir DB transaction'ı İÇİNDE çalışır (ör.
 * `DealMoveService::move()` — `$deal->save()` transaction içinde,
 * `broadcast(new DealMoved(...))` bilinçli olarak transaction DIŞINDA
 * çağrılıyor, aynı derste). `config/queue.php`'de her bağlantı için
 * `after_commit => false` (proje genelinde), yani varsayılan davranışta
 * kuyruğa itilen job, MySQL commit'inden ÖNCE Redis işçisi tarafından
 * alınabilir. `afterCommit()` bu job'u özel olarak "transaction commit
 * olmadan kuyruğa gitme" moduna sokar — DealMoved'in "yayın transaction
 * dışında" prensibiyle aynı sorunun, dispatch noktası servis metodunun
 * kontrolünde OLMADIĞI (observer/listener) için farklı bir çözümüdür.
 *
 * ---------------------------------------------------------------------------
 * `via()` SIRASI: `database` ÖNCE, `broadcast` SONRA
 * ---------------------------------------------------------------------------
 * `Illuminate\Notifications\NotificationSender::sendToNotifiable()` kanalları
 * `via()`'nın döndürdüğü SIRAYLA, aynı job içinde SENKRON işler. Sözleşme
 * `toBroadcast()`'in `unread_count`'u "bu kullanıcının GÜNCEL okunmamış
 * sayısı" olarak tanımlıyor — yani az önce yazılan satır da dahil. `database`
 * önce çalışmazsa sayaç bir eksik gelir.
 */
abstract class CrmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * NOT `readonly`: `Illuminate\Queue\SerializesModels::__unserialize()`
     * (vendor kaynağı okunarak doğrulandı) her özelliği `ReflectionProperty::
     * setValue()` ile geri yükler. PHP'nin queue payload'ı `serialize()`/
     * `unserialize()` ile üretilmesi (bkz. `Illuminate\Queue\Queue::
     * createObjectPayload()` — `QUEUE_CONNECTION=sync` DAHİL, sürücüden
     * bağımsız çalışır) alt sınıfta (`DealAssignedNotification` vb.) somut
     * hâle gelen bir nesneyi geri kurar; bu durumda Reflection'ın "hangi
     * scope'tan yazılıyor" kontrolü nesnenin ÇALIŞMA ZAMANI sınıfına
     * (alt sınıf) bakar, `readonly` özelliğin TANIMLANDIĞI sınıfa
     * (`CrmNotification`) değil — ve "Cannot initialize readonly property
     * ...\CrmNotification::$recipientId from scope ...\DealAssignedNotification"
     * hatasıyla fatal verir (küçük bir izole script ile ampirik olarak
     * doğrulandı). `readonly` yalnızca kozmetik bir savunma katmanıydı;
     * kaldırılması payload sözleşmesini DEĞİŞTİRMEZ.
     *
     * ---------------------------------------------------------------------------
     * FAZ 14 / İZ D — METİN YERİNE ANAHTAR+PARAMETRE
     * ---------------------------------------------------------------------------
     * Alt sınıflar artık İKİ moddan birinde yazılır:
     *
     *   ANAHTAR MODU (hedef): `titleKey`/`bodyKey`/`params` verilir, `notificationTitle`/
     *   `notificationBody` verilmez. `data` sütununa metin DEĞİL anlam yazılır; cümle okuma
     *   anında OKUYANIN diliyle üretilir (gerekçe: NotificationText).
     *
     *   DÜZ METİN MODU (miras): `notificationTitle`/`notificationBody` verilir. 11 tipin
     *   9'u hâlâ böyledir ve çalışmaya devam eder — dönüşüm kademelidir, tek seferlik bir
     *   "hepsini birden değiştir" hamlesi değildir.
     *
     * İki metin alanı ARTIK NULLABLE ve parametre SIRASI değişti; bu güvenlidir çünkü 12
     * alt sınıfın hepsi `new self(...)`'i ADLANDIRILMIŞ ARGÜMANLARLA çağırır (doğrulandı) —
     * konum değil ad bağlayıcıdır.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected int $recipientId,
        protected string $notificationType,
        protected string $notificationLink,
        protected array $meta,
        protected ?string $notificationTitle = null,
        protected ?string $notificationBody = null,
        protected ?string $titleKey = null,
        protected ?string $bodyKey = null,
        protected array $params = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * `notifications.data` sözleşmesi.
     *
     * ANAHTAR MODUNDA: `{ type, title_key, body_key, params, link, meta }` (PHASE-INTL §1.4).
     * Render edilmiş `title`/`body` BİLİNÇLİ OLARAK YAZILMAZ — yazılsaydı okuma anındaki
     * çözümün yanında ölü ve yanlış dilde bir kopya dururdu; ilk okuyan onu "yedek" sanıp
     * kullanmaya başladığında dil donması geri gelirdi.
     *
     * DÜZ METİN MODUNDA: Faz 10'un beş anahtarı aynen korunur.
     *
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        if ($this->titleKey !== null) {
            return [
                'type' => $this->notificationType,
                'title_key' => $this->titleKey,
                'body_key' => $this->bodyKey,
                'params' => $this->params,
                'link' => $this->notificationLink,
                'meta' => $this->meta,
            ];
        }

        return [
            'type' => $this->notificationType,
            'title' => $this->notificationTitle,
            'body' => $this->notificationBody,
            'link' => $this->notificationLink,
            'meta' => $this->meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * 5 sözleşme anahtarı + `id` (uuid) + `created_at` + `unread_count`.
     *
     * `$this->id`: `NotificationSender::sendToNotifiable()` her kanaldan ÖNCE
     * `$notification->id = $notificationId` yazar (vendor kaynağı okunarak
     * doğrulandı), yani `database` kanalındaki satırın birincil anahtarıyla
     * burası birebir aynı uuid'i taşır.
     *
     * ---------------------------------------------------------------------------
     * FAZ 14: YAYIN YÜKÜ HEM ANAHTAR+PARAMETRE HEM ÇÖZÜLMÜŞ METİN TAŞIR
     * ---------------------------------------------------------------------------
     * `data` sütunundan farklı olarak burada çözülmüş `title`/`body` DE gönderilir. Bu bir
     * çelişki değil, iki farklı nesnenin iki farklı ihtiyacıdır:
     *
     *   • DB satırı KALICIDIR ve yıllar sonra, kullanıcının o günkü diliyle okunur → metin
     *     saklamak dil donmasıdır (bkz. toArray()).
     *   • Yayın çerçevesi ANLIKTIR: tek bir alıcıya, şu an, kendisi için gönderilir. Alıcının
     *     dili gönderim anında BİLİNİR (`$notifiable->locale`), o yüzden metni burada çözmek
     *     hiçbir şeyi dondurmaz — çerçeve saniyeler içinde tüketilip atılır.
     *
     * İkisini birden göndermenin bedeli birkaç yüz bayt; karşılığında mevcut istemci
     * (`features/notifications`) hiç değişmeden çalışmaya devam eder ve anahtar+parametreyi
     * kendi tarafında render etmek isteyen ileriki bir istemci de gerekli veriyi bulur.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast($notifiable): array
    {
        $data = $this->toArray($notifiable);

        $locale = is_string($notifiable->locale ?? null) && $notifiable->locale !== ''
            ? $notifiable->locale
            : app()->getLocale();

        return array_merge($data, NotificationText::resolve($data, $locale), [
            'id' => $this->id,
            'created_at' => now()->toIso8601String(),
            'unread_count' => $notifiable->unreadNotifications()->count(),
        ]);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }
}
