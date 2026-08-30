<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dashboard'un altındaki veri değişti — panoyu açık tutan istemcilere
 * "hangi widget'lar bayatladı, yeniden çek" sinyali.
 *
 * ---------------------------------------------------------------------------
 * NEDEN PAYLOAD MODEL DEĞİL, `keys` DİZİSİ
 * ---------------------------------------------------------------------------
 * DealMoved'daki aynı gerekçe geçerli (bkz. o sınıfın docblock'u): olay
 * ölçeklenebilir bir sayı taşımaz, yalnızca HANGİ dashboard metriklerinin
 * (`kpis`, `funnel`, `revenue-trend`, ...) artık bayat olduğunu söyler.
 * İstemci kendi TanStack Query önbelleğini bu anahtarlarla invalidate eder
 * ve gerçek veriyi normal REST uçlarından (GET /api/dashboard/...) çeker —
 * soket asla veri taşımaz, yalnızca "şimdi tekrar sor" der. Bu hem payload'ı
 * küçük tutar hem de KPI hesaplama mantığının TEK yerde (DashboardService)
 * kalmasını sağlar; soket ile REST iki ayrı hesaplama yolu üretmez.
 *
 * `toOthers()` KASITLI OLARAK kullanılmaz (bkz. dispatch noktası —
 * App\Listeners\Dashboard\InvalidateDashboardOnDealMoved): kartı taşıyan
 * kullanıcının kendi açık dashboard sekmesi de bayatlar, o da haberdar
 * edilmeli.
 */
class DashboardInvalidated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $keys  bayatlayan metrik/anahtar adları (ör. ['kpis', 'funnel', 'revenue-trend'])
     */
    public function __construct(public readonly array $keys) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.invalidate';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['keys' => $this->keys];
    }
}
