<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bir görevin hatırlatıcısı (`reminder_at`) vadesi geldi.
 *
 * `App\Console\Commands\DispatchTaskReminders` (`tasks:dispatch-reminders`,
 * her dakika zamanlanır — bkz. routes/console.php) tarafından dispatch
 * edilir.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `private-user.{assigned_to}` (MEVCUT KANAL)
 * ---------------------------------------------------------------------------
 * Bir hatırlatıcı KİŞİSEL bir bildirimdir — görevi başkası değil, yalnızca
 * atanan kişi görmelidir. `private-user.{id}` zaten Faz 4'te
 * (UserDeactivated ile) kuruldu ve routes/channels.php'de
 * `$user->id === (int) $id` ile yetkilendiriliyor; yeni bir kanal açmaya
 * gerek yok, aynı kişisel kanal bu olay için de doğru kapsam.
 *
 * ---------------------------------------------------------------------------
 * NEDEN DÜZ SKALER PAYLOAD (MODEL DEĞİL)
 * ---------------------------------------------------------------------------
 * Faz 5/7'de öğrenildi: SerializesModels kuyruğa yalnızca sınıf+id koyar ve
 * işçi satırı YENİDEN SORGULAR — dispatch anındaki durumla işçinin gördüğü
 * durum arasında (ör. görev bu arada tamamlandıysa) fark oluşabilir. Bu
 * yüzden yayınlanacak her şey, dispatch anında hesaplanıp skaler olarak
 * taşınır (bkz. DealMoved::payload() aynı gerekçe).
 *
 * ---------------------------------------------------------------------------
 * FAZ NOTU
 * ---------------------------------------------------------------------------
 * Bu yalnızca IN-APP (uygulama-içi) bir sinyaldir — açık bir sekmede anlık
 * toast/rozet göstermek için. E-posta bildirimi ve kalıcı bildirim merkezi
 * (okundu/okunmadı listesi) Faz 10'un işidir; bu event onun ALTYAPISINI
 * kurmaz, yalnızca gerçek zamanlı kanalı kullanır.
 */
class TaskReminderDue implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $assignedTo  Hatırlatıcının gideceği kullanıcı — kanalın hedefi.
     * @param  array<string, mixed>  $payload  bkz. sınıf dokümanı — task_id, title,
     *                                         due_at, priority, taskable_type,
     *                                         taskable_id, taskable_label.
     */
    public function __construct(
        public readonly int $assignedTo,
        public readonly array $payload,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->assignedTo),
        ];
    }

    /**
     * Kısa, sabit olay adı — FQCN yayınlamak SPA'yı backend namespace'ine
     * bağlardı (bkz. ActivityLogged/DealMoved/UserDeactivated aynı sözleşme).
     */
    public function broadcastAs(): string
    {
        return 'task.reminder';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
