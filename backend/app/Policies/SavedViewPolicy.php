<?php

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;
use App\Services\SavedViews\SavedViewModules;
use Illuminate\Support\Facades\Gate;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.4).
 *
 * İKİ AYRI YETKİ BOYUTU vardır ve KARIŞTIRILMAMALIDIR:
 *   1) MODÜL erişimi — bir kullanıcı `deals` modülü için bir görünümü görebilir/
 *      oluşturabilir mi? Bu, `viewAny(User, string $module)` ve `create(User, string $module)`
 *      metotlarında `Gate::allows('viewAny', ModelClass)` ile ilgili modülün KENDİ
 *      Policy'sine (DealPolicy/LeadPolicy/...) devredilir — `GlobalSearchService` (C1) ile
 *      AYNI karar: kendi izin mantığı İCAT EDİLMEDİ.
 *   2) GÖRÜNÜM sahipliği — bir kayıtlı görünümü kim DÜZENLEYEBİLİR/SİLEBİLİR? Bu SADECE
 *      `user_id` eşleşmesidir (`update()`/`delete()`), `is_shared` bunu DEĞİŞTİRMEZ:
 *      paylaşılan bir görünüm herkese GÖRÜNÜR ve UYGULANABİLİR ama yalnızca sahibi
 *      DÜZENLEYEBİLİR/SİLEBİLİR (görev tanımı §"Sahiplik/paylaşım").
 *
 * CONFUSED DEPUTY KİLİDİ (§5.4, BAĞLAYICI): bu Policy'nin HİÇBİR metodu veri (deal/lead/...
 * satırı) DÖNDÜRMEZ ya da başka bir kullanıcının izniyle bir sorgu ÇALIŞTIRMAZ — yalnızca
 * "bu SavedView METADATA'sını görebilir/değiştirebilir misin" sorusuna cevap verir. Gerçek
 * veri her zaman `SavedViewController`'ın DIŞINDAki normal liste ucundan (DealController::
 * index() vb.), AÇAN kullanıcının kendi Sanctum oturumu ve kendi Policy kontrolüyle çekilir
 * — SavedView bu akışa asla dahil olmaz, sadece filtre parametrelerini taşır.
 */
class SavedViewPolicy
{
    public function viewAny(User $user, string $module): bool
    {
        $modelClass = SavedViewModules::modelClass($module);

        return $modelClass !== null && Gate::forUser($user)->allows('viewAny', $modelClass);
    }

    /**
     * Tekil bir görünüme erişim: sahibi HERKESİN, paylaşılmamışsa YALNIZCA sahibi görebilir
     * — bunun ÜSTÜNE modül erişimi de (`viewAny`) aranır, aksi halde `deals.view` izni
     * sonradan alınan bir kullanıcı, izni ALMADAN ÖNCE paylaşılmış bir `deals` görünümünü
     * göremezdi diye bir beklenti YOKTUR (izin varsa zaten görebilir); ama izni HİÇ
     * OLMAYAN biri paylaşılan bir görünümün varlığından bile haberdar OLMAMALIDIR.
     */
    public function view(User $user, SavedView $savedView): bool
    {
        if (! $savedView->is_shared && $savedView->user_id !== $user->id) {
            return false;
        }

        return $this->viewAny($user, $savedView->module);
    }

    public function create(User $user, string $module): bool
    {
        return $this->viewAny($user, $module);
    }

    public function update(User $user, SavedView $savedView): bool
    {
        return $savedView->user_id === $user->id;
    }

    public function delete(User $user, SavedView $savedView): bool
    {
        return $savedView->user_id === $user->id;
    }
}
