<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksRecordOwnership;

class TicketPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(User $user): bool
    {
        return $user->can('tickets.view');
    }

    /**
     * Okuma BİLEREK düz (bkz. ChecksRecordOwnership dokümanı).
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.view');
    }

    public function create(User $user): bool
    {
        return $user->can('tickets.create');
    }

    /**
     * Yatay yazma izolasyonu; sahiplik kolonu `assigned_to`.
     *
     * PAYLAŞILAN DESTEK KUYRUĞU BOZULMAZ: `Destek Temsilcisi` rolünün TAMAMI
     * `tickets.assign` iznini taşır (bkz. RolePermissionSeeder) — yani bugün
     * bir talebe dokunabilen herkes yarın da dokunabilecek. Kural, `tickets.
     * update` iznine sahip olup `tickets.assign` taşımayan ileride
     * tanımlanacak dar rolleri (ör. yalnız kendi talebini yürüten bir dış
     * kaynak rolü) kapsar. Atanmamış talepler (`assigned_to` NULL) havuzdadır
     * ve herkese açıktır — ilk yanıt veren üstlenir.
     *
     * `PATCH /api/tickets/{ticket}/status` de bu metottan geçer:
     * TicketController::status() bilerek `Gate::authorize('update', ...)`
     * çağırır (izin sözlüğünde `tickets.status` diye bir satır yok), bu yüzden
     * durum değişimi ayrı bir policy metoduna gerek kalmadan aynı yatay
     * sınıra tabidir.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if (! $user->can('tickets.update')) {
            return false;
        }

        return $this->ownsOrManages($user, $ticket->assigned_to, 'tickets.assign');
    }

    /**
     * =========================================================================
     * ÇÖZÜLMÜŞ VEYA KAPANMIŞ BİR TICKET SİLİNEMEZ
     * =========================================================================
     *
     * `resolved` ve `closed` bir ticket'ın SLA SONUCU YAZILMIŞ hâlidir:
     * `resolved_at` ile `sla_due_at` karşılaştırması Faz 11'in "SLA uyum
     * oranı", "ortalama çözüm süresi" ve "kullanıcı performansı" raporlarının
     * TEK girdisidir. Böyle bir kaydı silmek — soft delete olsa bile, çünkü
     * her sorgu varsayılan olarak `deleted_at IS NULL` süzer — geçmiş bir
     * dönemin uyum yüzdesini GERİYE DÖNÜK ve sessizce değiştirir. Bir ihlali
     * ortadan kaldırmanın en kolay yolu "ticket'ı sil" olmamalıdır.
     *
     * Bu, docs/SLA-DESIGN.md §4'ün `closed` durumunu terminal yapan
     * gerekçesinin (kapanmış dönem raporları geriye dönük değişmez kalmalı)
     * silme tarafındaki karşılığıdır: durum makinesi kapanmış bir ticket'ı
     * yeniden açtırmıyorsa, silme ucu da onu yok ettirmemelidir.
     *
     * Hâlâ AÇIK olan (`open` / `in_progress` / `pending`) ticket'lar
     * silinebilir: yanlışlıkla açılmış, mükerrer ya da test amaçlı kayıtlar
     * bunlardır ve henüz hiçbir raporun girdisi değildirler.
     *
     * Silmede sahiplik kontrolü YOK: `tickets.delete` yalnızca Admin/Super
     * Admin'de ve bu roller zaten `tickets.assign` taşıyor — no-op olurdu.
     *
     * 403 tercih edildi (422 değil), Faz 6/7'deki iki emsalle aynı desen:
     * LeadPolicy::delete() dönüştürülmüş lead'i, DealPolicy::delete()
     * kazanılmış/kaybedilmiş deal'i aynı şekilde reddeder. "Kim silebilir" ile
     * "hangi kayıt silinebilir" kararını tek yerde (Policy) toplamak, ikinci
     * kuralı Service katmanına ayrı bir 422 olarak dağıtmaktan tutarlıdır.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        if (! $user->can('tickets.delete')) {
            return false;
        }

        return ! in_array($ticket->status, ['resolved', 'closed'], true);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.assign');
    }
}
