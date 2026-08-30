<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    /**
     * Determine whether the user can view any contacts.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.view');
    }

    /**
     * Determine whether the user can view the given contact.
     */
    public function view(User $user, Contact $contact): bool
    {
        return $user->can('contacts.view');
    }

    /**
     * Determine whether the user can create contacts.
     */
    public function create(User $user): bool
    {
        return $user->can('contacts.create');
    }

    /**
     * =========================================================================
     * KAPSAM DIŞI: yatay yazma izolasyonu (Model C) BU MODÜLE UYGULANMADI
     * =========================================================================
     *
     * Faz 13'te Deal/Lead/Task/Ticket/Activity için "yazma yalnızca sahip,
     * sahipsiz kayıt veya `*.assign` taşıyan yönetici" kuralı getirildi (bkz.
     * App\Policies\Concerns\ChecksRecordOwnership). Contact BİLEREK dışarıda
     * bırakıldı:
     *
     * 1. Bu bir PAYLAŞILAN ADRES DEFTERİ / master data tablosudur, bir iş
     *    kaydı değil. Aynı firmayı iki temsilci arar, aynı kişinin telefonu
     *    kimin "sahibi" olduğuna bakılmadan güncel tutulmalıdır; sahiplik
     *    sınırı burada veriyi güncel tutmayı ENGELLERDİ.
     * 2. `contacts.assign` DİYE BİR İZİN YOK (bkz. RolePermissionSeeder izin
     *    sözlüğü) — kuralın "yönetici" ayağı bu modülde tanımsızdır; kontrolü
     *    yine de uygulamak, sahibi olan bir kaydı yalnızca o kişiye kilitler
     *    ve devretmenin hiçbir yolu kalmazdı (`/assign` ucu da yok).
     * 3. `owner_id` kayıtların çoğunda BOŞTUR; kural fiilen yalnızca sahibi
     *    doldurulmuş azınlığı cezalandırırdı — tutarsız bir yarı-kural.
     *
     * Kalan koruma yeterli görüldü: her değişiklik `activity_log`'a düşer
     * (spatie) ve `contacts.delete` yalnızca Müdür/Admin/Super Admin'dedir —
     * yani geri alınamayan tek işlem zaten yönetici elindedir.
     */
    /**
     * Determine whether the user can update the given contact.
     */
    public function update(User $user, Contact $contact): bool
    {
        return $user->can('contacts.update');
    }

    /**
     * Determine whether the user can delete the given contact.
     */
    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('contacts.delete');
    }
}
