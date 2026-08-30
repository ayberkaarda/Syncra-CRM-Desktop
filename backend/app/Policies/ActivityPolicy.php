<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\ChecksRecordOwnership;

class ActivityPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(User $user): bool
    {
        return $user->can('activities.view');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('activities.view');
    }

    public function create(User $user): bool
    {
        return $user->can('activities.create');
    }

    /**
     * KARAR: bir aktiviteyi yalnızca onu YAZAN kişi güncelleyebilir;
     * `activities.delete` taşıyan bir yönetici başkasınınkini de düzeltebilir.
     * Yani delete() ile BİREBİR aynı sahiplik mantığı.
     *
     * GEREKÇE: aktivite geçmiş bir ETKİLEŞİMİN kaydıdır — delete()'in aynı
     * gerekçesi (başkasının görüşme notunu yok edememek) düzenleme için de
     * geçerlidir, hatta daha sinsidir: silinen bir not en azından kaybolur,
     * DEĞİŞTİRİLEN bir not yanlış içeriğiyle doğru sanılır. Ticket iç notları
     * da bu tabloda yaşadığı için kural destek tarafını da kapsar.
     *
     * `activities.assign` DİYE BİR İZİN YOK; bu modülde yönetici sinyali
     * `activities.delete`'tir (Müdür/Admin'de var, temsilcilerde yok) ve
     * delete() zaten aynı izni aynı rolde kullanıyor — iki metot tek ve aynı
     * sahiplik mantığını paylaşsın diye trait'e o izin geçiliyor.
     */
    public function update(User $user, Activity $activity): bool
    {
        if (! $user->can('activities.update')) {
            return false;
        }

        // Yazarı silinmiş (user_id NULL) bir kayıt "havuza düşmüş" SAYILMAZ:
        // sahipsiz bir deal'den farklı olarak bu, hesabı silinen bir kullanıcıdan
        // ÖKSÜZ KALMIŞ bir geçmiş kaydıdır — sahiplenilip yürütülecek bir iş
        // değil. delete() ile aynı davranış: yalnızca yönetici dokunabilir.
        if ($activity->user_id === null) {
            return $user->can('activities.delete');
        }

        return $this->ownsOrManages($user, $activity->user_id, 'activities.delete');
    }

    /**
     * KARAR: bir aktiviteyi yalnızca (a) onu OLUŞTURAN kişi, ya da (b)
     * `activities.delete` iznine sahip bir yönetici silebilir.
     *
     * GEREKÇE: bir aktivite (çağrı/toplantı/e-posta/not) geçmiş bir
     * ETKİLEŞİMİN kaydıdır — bir deal/lead gibi "yaşayan" bir varlık değil,
     * ne olduğunun izidir. Başka bir temsilcinin müşteriyle yaptığı
     * görüşmenin notunu silebilmek, o etkileşimin hiç olmamış gibi
     * görünmesine yol açar ve ekip geçmişini bozar — bu yüzden varsayılan
     * olarak yalnızca KAYDI YAZAN kişi kendi hatasını (yanlış girilen bir
     * not, yinelenen bir kayıt) düzeltebilir. `activities.delete` izni,
     * bunun ÜSTÜNE, bir yöneticinin (ör. ayrılan bir çalışanın kayıtlarını
     * temizlerken) BAŞKASININ aktivitesini de silebilmesini sağlar — iki
     * yol da açık, ama biri KİMLİĞE (creator), diğeri İZNE dayanıyor ve
     * ikisi birbirinin YERİNE değil TAMAMLAYICISI.
     *
     * Creator kontrolü `activities.delete` iznine SAHİP OLMAYI şart
     * KOŞMAZ: `activities.create` iznine sahip her kullanıcı zaten kendi
     * yazdığı notu silebilmelidir (aksi halde yanlış girilen bir kayıt,
     * yöneticiye gidilmeden düzeltilemez bir hal alır).
     */
    public function delete(User $user, Activity $activity): bool
    {
        if ($user->can('activities.delete')) {
            return true;
        }

        return $activity->user_id !== null && $activity->user_id === $user->id;
    }
}
