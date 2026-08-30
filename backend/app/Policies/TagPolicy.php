<?php

namespace App\Policies;

use App\Models\User;

class TagPolicy
{
    /**
     * Etiket listesi paylaşılan bir arama/lookup verisidir (yalnızca 12
     * kayıt) — leads/contacts/companies formlarında etiket seçici için
     * gereklidir, bu yüzden herhangi bir modül izni aranmaz; kimliği
     * doğrulanmış her kullanıcı görebilir.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Etiket oluşturma için AYRI bir `tags.create` izni yok. Etiketler
     * yalnızca lead/contact/company formlarından "yeni etiket" akışıyla
     * oluşturulur; bu üç modülden herhangi birinde oluşturma yetkisi olan
     * bir kullanıcı, o kaydı etiketlerken yeni bir etiket de üretebilmelidir.
     */
    public function create(User $user): bool
    {
        return $user->can('leads.create')
            || $user->can('contacts.create')
            || $user->can('companies.create');
    }
}
