<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    /**
     * Determine whether the user can view any companies.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('companies.view');
    }

    /**
     * Determine whether the user can view the given company.
     */
    public function view(User $user, Company $company): bool
    {
        return $user->can('companies.view');
    }

    /**
     * Determine whether the user can create companies.
     */
    public function create(User $user): bool
    {
        return $user->can('companies.create');
    }

    /**
     * =========================================================================
     * KAPSAM DIŞI: yatay yazma izolasyonu (Model C) BU MODÜLE UYGULANMADI
     * =========================================================================
     *
     * Faz 13'te Deal/Lead/Task/Ticket/Activity için "yazma yalnızca sahip,
     * sahipsiz kayıt veya `*.assign` taşıyan yönetici" kuralı getirildi (bkz.
     * App\Policies\Concerns\ChecksRecordOwnership). Company BİLEREK dışarıda
     * bırakıldı:
     *
     * 1. Bu bir PAYLAŞILAN ADRES DEFTERİ / master data tablosudur, bir iş
     *    kaydı değil. Aynı firmayı iki temsilci arar, aynı kişinin telefonu
     *    kimin "sahibi" olduğuna bakılmadan güncel tutulmalıdır; sahiplik
     *    sınırı burada veriyi güncel tutmayı ENGELLERDİ.
     * 2. `companies.assign` DİYE BİR İZİN YOK (bkz. RolePermissionSeeder izin
     *    sözlüğü) — kuralın "yönetici" ayağı bu modülde tanımsızdır; kontrolü
     *    yine de uygulamak, sahibi olan bir kaydı yalnızca o kişiye kilitler
     *    ve devretmenin hiçbir yolu kalmazdı (`/assign` ucu da yok).
     * 3. `owner_id` kayıtların çoğunda BOŞTUR; kural fiilen yalnızca sahibi
     *    doldurulmuş azınlığı cezalandırırdı — tutarsız bir yarı-kural.
     *
     * Kalan koruma yeterli görüldü: her değişiklik `activity_log`'a düşer
     * (spatie) ve `companies.delete` yalnızca Müdür/Admin/Super Admin'dedir —
     * yani geri alınamayan tek işlem zaten yönetici elindedir.
     */
    /**
     * Determine whether the user can update the given company.
     */
    public function update(User $user, Company $company): bool
    {
        return $user->can('companies.update');
    }

    /**
     * Determine whether the user can delete the given company.
     */
    public function delete(User $user, Company $company): bool
    {
        return $user->can('companies.delete');
    }
}
