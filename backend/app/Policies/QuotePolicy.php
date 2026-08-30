<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can('quotes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.create');
    }

    /**
     * =========================================================================
     * KAPSAM DIŞI: yatay yazma izolasyonu (Model C) BU MODÜLE UYGULANMADI
     * =========================================================================
     *
     * Faz 13'te Deal/Lead/Task/Ticket/Activity için "yazma yalnızca sahip,
     * sahipsiz kayıt veya `*.assign` taşıyan yönetici" kuralı getirildi (bkz.
     * App\Policies\Concerns\ChecksRecordOwnership). Teklifler BİLEREK dışarıda:
     * `quotes.update` bugünkü izin matrisinde YALNIZCA Satış Müdürü, Admin ve
     * Super Admin'dedir — Satış Temsilcisi teklif OLUŞTURUR (`quotes.create`)
     * ama güncelleyemez. Yani bu ucun tamamı zaten yönetici düzeyindedir ve
     * eklenecek bir sahiplik kontrolü hiçbir çağrıyı reddetmez: no-op olurdu.
     *
     * Ayrıca `quotes` tablosunda `owner_id` YOK; kuralın dayanacağı bir
     * sahiplik kolonu bulunmuyor (teklif, `deal` üzerinden dolaylı olarak
     * sahiplenilir — ve o deal artık korunuyor). İzin matrisi ileride
     * `quotes.update`'i temsilcilere açarsa bu karar YENİDEN GÖZDEN
     * GEÇİRİLMELİDİR.
     */
    public function update(User $user, Quote $quote): bool
    {
        return $user->can('quotes.update');
    }

    /**
     * =========================================================================
     * KABUL VEYA RED EDİLMİŞ BİR TEKLİF SİLİNEMEZ
     * =========================================================================
     *
     * `accepted` bir teklif, kurulan ticari ilişkinin belgesidir: siparişin ve
     * faturanın dayanağı, Faz 11'deki "kazanma oranı" ve gelir raporlarının
     * girdisidir. `rejected` de aynı ölçüde veridir — kaybedilen işler
     * silinebiliyorsa kazanma oranı anlamını yitirir.
     *
     * Soft delete olması bu sakıncayı KALDIRMAZ: her sorgu varsayılan olarak
     * `deleted_at IS NULL` süzer, dolayısıyla silinen bir teklif geçmiş bir
     * dönemin rakamlarını GERİYE DÖNÜK ve sessizce değiştirir. Bir reddi
     * ortadan kaldırmanın en kolay yolu "teklifi sil" olmamalıdır.
     *
     * `draft`, `sent` ve `expired` teklifler SİLİNEBİLİR: yanlışlıkla
     * açılmış, mükerrer ya da sonuçlanmadan düşmüş kayıtlardır ve henüz
     * hiçbir sonuç raporunun girdisi değildirler.
     *
     * 403 tercih edildi (422 değil), Faz 6/7/8'deki üç emsalle aynı desen:
     * LeadPolicy::delete() dönüştürülmüş lead'i, DealPolicy::delete()
     * kazanılmış/kaybedilmiş deal'i, TicketPolicy::delete() çözülmüş/kapanmış
     * ticket'ı aynı şekilde reddeder. "Kim silebilir" ile "hangi kayıt
     * silinebilir" kararını tek yerde (Policy) toplamak, ikinci kuralı Service
     * katmanına ayrı bir 422 olarak dağıtmaktan tutarlıdır.
     */
    public function delete(User $user, Quote $quote): bool
    {
        if (! $user->can('quotes.delete')) {
            return false;
        }

        return ! in_array($quote->status, ['accepted', 'rejected'], true);
    }

    /**
     * `POST /api/quotes/{quote}/send` — AYRI bir izin (`quotes.send`).
     *
     * Teklifi müşteriye göndermek, onu düzenlemekten farklı bir yetkidir:
     * izin sözlüğünde `quotes.update` ile `quotes.send` ayrı satırlardır ve
     * demo rollerde bir kullanıcının teklif hazırlayıp gönderememesi geçerli
     * bir kurgudur (bkz. RolePermissionSeeder). Gönderim, dışarıya taahhüt
     * doğuran ve geri alınamayan tek yazma işlemidir — gönderildikten sonra
     * tutar kilitlenir.
     */
    public function send(User $user, Quote $quote): bool
    {
        return $user->can('quotes.send');
    }
}
