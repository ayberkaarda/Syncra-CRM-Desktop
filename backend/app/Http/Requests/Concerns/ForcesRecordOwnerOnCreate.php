<?php

namespace App\Http\Requests\Concerns;

/**
 * =============================================================================
 * OLUŞTURMA AYAĞINDA SAHİP SAHTECİLİĞİ (Faz 13 / F8'in ikinci yarısı)
 * =============================================================================
 * `Update*Request`'lerde `owner_id`/`assigned_to` artık `missing` — devretme
 * yalnızca `*.assign` izniyle korunan `/assign` ucundan yapılıyor. Ama kapı
 * bir tanesi kapanıp diğeri açık kalırsa hiçbir şey kazanılmaz: `*.assign`
 * izni OLMAYAN bir kullanıcı, kaydı DOĞRUDAN başkasının adına OLUŞTURARAK
 * aynı sonuca varabilirdi (ör. bir temsilcinin panosuna onun haberi olmadan
 * deal düşürmek, ya da bir başkasına görev "atamak").
 *
 * KARAR: `*.assign` izni olmayan aktörün gönderdiği sahip alanı REDDEDİLMEZ,
 * SESSİZCE kendi kimliğine SABİTLENİR.
 *
 * GEREKÇE (neden 422/403 değil): burada `Update*Request`'ten farklı olarak
 * ortada baypas edilen bir uç YOK — "kendi adına kayıt açmak" zaten meşru ve
 * en sık yapılan iştir. Alanı hata yapmak, hiçbir yetki kazandırmayan sıradan
 * bir istemci alışkanlığını (formun sahip alanını her zaman doldurup
 * göndermek) kırardı. Sunucunun doğru sahibi kendisinin YAZMASI, bu projede
 * `created_by`/`ticket_number`/`status` için zaten kullanılan desenin
 * (istemciden kabul etme, sunucu üret) aynısıdır.
 *
 * Alan hiç gönderilmemiş olsa bile sabitlenir: sahipsiz kayıt yalnızca
 * `*.assign` taşıyan birinin BİLEREK havuza bıraktığı kayıt olmalıdır —
 * yoksa herkesin yazabildiği (bkz. ChecksRecordOwnership) sahipsiz kayıtlar
 * yatay izolasyonu sessizce delen bir kaçak hâline gelirdi.
 */
trait ForcesRecordOwnerOnCreate
{
    /**
     * @param  string  $field  `owner_id` veya `assigned_to`.
     * @param  string  $assignPermission  Modülün devretme izni (ör. `deals.assign`).
     */
    protected function forceOwnerUnlessAssigner(string $field, string $assignPermission): void
    {
        $user = $this->user();

        if ($user === null || $user->can($assignPermission)) {
            return;
        }

        $this->merge([$field => $user->getKey()]);
    }
}
