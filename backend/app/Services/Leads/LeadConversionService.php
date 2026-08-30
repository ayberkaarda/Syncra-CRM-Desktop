<?php

namespace App\Services\Leads;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Support\FractionalIndex;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lead -> Contact (+ Company, + Deal) dönüşümü.
 *
 * ---------------------------------------------------------------------------
 * NEDEN TEK TRANSACTION
 * ---------------------------------------------------------------------------
 * Dönüşüm tek bir iş kararıdır ama 5-6 tabloya yazar: firma, kişi, fırsat,
 * görev/aktivite/ek/etiket morph hedefleri ve lead'in kendisi. Ortada bir hata
 * olursa (aşama silinmiş, FK ihlali, disk dolu) yarım dönüşüm kalırsa sonuç
 * SESSİZ VERİ BOZULMASIDIR: sahipsiz bir Contact ve hâlâ "new" görünen bir
 * lead. Kullanıcı ikinci kez dönüştürür, ikinci bir müşteri kaydı oluşur.
 * Bu yüzden her şey tek `DB::transaction` içindedir.
 *
 * ---------------------------------------------------------------------------
 * NEDEN İDEMPOTENT DEĞİL
 * ---------------------------------------------------------------------------
 * "Zaten dönüşmüş" durumu sessizce başarılı sayılmaz, 422 ile REDDEDİLİR.
 * Sessiz kabul (mevcut contact'ı geri döndürmek) çekici görünür ama iki
 * kullanıcının aynı lead'i aynı anda dönüştürdüğü durumu gizler; açık hata,
 * kullanıcıya "bu iş zaten yapıldı, şuraya bak" diyebilmenin tek yoludur.
 * Yarış koşulu için satır kilidi de alınır (bkz. `lockLead()`).
 *
 * ---------------------------------------------------------------------------
 * LEAD SİLİNMEZ
 * ---------------------------------------------------------------------------
 * Dönüşen lead kayıt olarak KALIR; yalnızca `status`/`converted_*` alanları
 * işaretlenir. Lead, "bu müşteri nereden geldi" sorusunun tek cevabıdır
 * (`source`, `score`, ilk temas tarihi); silinirse kaynak raporları geçmişe
 * dönük olarak boşalır.
 *
 * ---------------------------------------------------------------------------
 * YETKİ
 * ---------------------------------------------------------------------------
 * Bu serviste yetki kontrolü YOKTUR — Policy/Gate kontrolü controller'ın işi.
 * `$actor` yalnızca sahipsiz lead'lerde owner ataması için kullanılır (audit
 * causer'ını spatie zaten oturumdan çözer).
 */
class LeadConversionService
{
    /**
     * `custom_fields.entity_type` değerleri (CustomFieldSeeder ile aynı sözlük).
     */
    private const ENTITY_LEADS = 'leads';

    private const ENTITY_CONTACTS = 'contacts';

    /**
     * @param  array{create_deal?: bool, deal_title?: ?string, deal_amount?: ?float, company_id?: ?int, contact_id?: ?int}  $options
     * @return array{contact: Contact, company: ?Company, deal: ?Deal, lead: Lead}
     *
     * @throws ValidationException
     */
    public function convert(Lead $lead, array $options, User $actor): array
    {
        return DB::transaction(function () use ($lead, $options, $actor): array {
            $this->lockLead($lead);
            $this->guardNotConverted($lead);

            $company = $this->resolveCompany($lead, $options, $actor);
            $contact = $this->resolveContact($lead, $company, $options, $actor);
            $deal = $this->createDealIfRequested($lead, $contact, $company, $options, $actor);

            $this->moveRelatedRecords($lead, $contact);
            $this->copyCustomFieldValues($lead, $contact);

            $lead->forceFill([
                'status' => 'converted',
                'converted_at' => now(),
                'converted_contact_id' => $contact->getKey(),
                'converted_company_id' => $company?->getKey(),
                'converted_deal_id' => $deal?->getKey(),
            ])->save();

            return [
                'contact' => $contact,
                'company' => $company,
                'deal' => $deal,
                'lead' => $lead,
            ];
        });
    }

    /**
     * Lead satırını transaction süresince kilitler ve çağıranın elindeki model
     * örneğini kilitli satırın GERÇEK hâliyle tazeler.
     *
     * Kilit olmadan iki eşzamanlı istek de "henüz dönüşmemiş" kontrolünü
     * geçebilir ve aynı lead'den iki Contact üretilebilirdi — `leads` tablosunda
     * bunu engelleyecek bir unique kısıt yok, tek koruma bu kilit.
     *
     * Çağıranın örneği güncellenmezse bayat `status` üzerinden karar verilir;
     * bu yüzden kilitli satırın attribute'ları örneğe geri yazılır.
     *
     * @throws ValidationException
     */
    private function lockLead(Lead $lead): void
    {
        /** @var Lead|null $locked */
        $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            // Araya giren bir soft delete ya da hiç kaydedilmemiş model.
            throw ValidationException::withMessages([
                'lead' => 'Dönüştürülecek aday kaydı bulunamadı.',
            ]);
        }

        $lead->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * @throws ValidationException
     */
    private function guardNotConverted(Lead $lead): void
    {
        if ($lead->status !== 'converted' && $lead->converted_at === null) {
            return;
        }

        throw ValidationException::withMessages([
            'lead' => 'Bu aday zaten müşteriye dönüştürülmüş; ikinci kez dönüştürülemez.',
        ]);
    }

    /**
     * Firma: verilmişse mevcut kayda bağlanır, verilmemişse `company_name`
     * doluysa yenisi açılır. `company_name` boşsa firma OLUŞTURULMAZ — adı
     * olmayan bir Company kaydı ("Adsız Firma") listeleri kirletir ve sonradan
     * temizlenmesi gereken çöp veri üretir.
     *
     * @param  array{company_id?: ?int}  $options
     *
     * @throws ValidationException
     */
    private function resolveCompany(Lead $lead, array $options, User $actor): ?Company
    {
        $companyId = $options['company_id'] ?? null;

        if ($companyId !== null) {
            /** @var Company|null $company */
            $company = Company::query()->whereKey($companyId)->first();

            if ($company === null) {
                throw ValidationException::withMessages([
                    'company_id' => 'Seçilen firma bulunamadı.',
                ]);
            }

            return $company;
        }

        $name = trim((string) $lead->company_name);

        if ($name === '') {
            return null;
        }

        return Company::create([
            'name' => $name,
            'owner_id' => $lead->owner_id ?? $actor->getKey(),
        ]);
    }

    /**
     * Kişi: verilmişse mevcut kayda bağlanır (ve firma çözüldüyse kişinin
     * firması güncellenir — duplicate tespiti "bu kişi zaten var ama firması
     * yanlış/eksik" dediğinde beklenen davranış budur), verilmemişse lead'in
     * alanlarından yenisi açılır.
     *
     * @param  array{contact_id?: ?int}  $options
     *
     * @throws ValidationException
     */
    private function resolveContact(Lead $lead, ?Company $company, array $options, User $actor): Contact
    {
        $contactId = $options['contact_id'] ?? null;

        if ($contactId !== null) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->whereKey($contactId)->first();

            if ($contact === null) {
                throw ValidationException::withMessages([
                    'contact_id' => 'Seçilen kişi bulunamadı.',
                ]);
            }

            if ($company !== null && $contact->company_id !== $company->getKey()) {
                $contact->company_id = $company->getKey();
                $contact->save();
            }

            return $contact;
        }

        // `notes` da taşınır: lead üzerindeki serbest notlar çoğu zaman
        // müşteriyle ilgili tek bağlam bilgisidir, dönüşümde kaybolmamalı.
        return Contact::create([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'position' => $lead->position,
            'company_id' => $company?->getKey(),
            'owner_id' => $lead->owner_id ?? $actor->getKey(),
            'notes' => $lead->notes,
        ]);
    }

    /**
     * @param  array{create_deal?: bool, deal_title?: ?string, deal_amount?: ?float}  $options
     *
     * @throws ValidationException
     */
    private function createDealIfRequested(Lead $lead, Contact $contact, ?Company $company, array $options, User $actor): ?Deal
    {
        if (! ($options['create_deal'] ?? false)) {
            return null;
        }

        // Aşama HARD-CODE EDİLMEZ: `pipeline_stages` yönetilebilir bir tablo,
        // ilk aşamanın adı da id'si de değişebilir. Sözleşme "position değeri
        // en küçük AKTİF aşama"; id ikincil sıralama yalnızca eşit position
        // durumunda deterministik davranmak için.
        /** @var PipelineStage|null $stage */
        $stage = PipelineStage::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($stage === null) {
            throw ValidationException::withMessages([
                'create_deal' => 'Aktif bir pipeline aşaması bulunamadığı için fırsat oluşturulamadı.',
            ]);
        }

        $title = trim((string) ($options['deal_title'] ?? ''));

        if ($title === '') {
            $title = $this->defaultDealTitle($lead, $company);
        }

        return Deal::create([
            'title' => $title,
            'amount' => $options['deal_amount'] ?? 0,
            'pipeline_stage_id' => $stage->getKey(),
            'position' => $this->nextPositionInStage((int) $stage->getKey()),
            'version' => 1,
            'status' => 'open',
            'company_id' => $company?->getKey(),
            'contact_id' => $contact->getKey(),
            // Sahipsiz lead'den doğan deal SAHİPSİZ KALMAZ: dönüştüren kişiye
            // yazılır — üç satır yukarıdaki contact/company ile aynı kural.
            // Faz 13'ten sonra bunun bir de yetki sonucu var: sahipsiz kayda
            // `deals.update` taşıyan HERKES yazabilir (bkz.
            // App\Policies\Concerns\ChecksRecordOwnership), yani dönüşümün
            // ürünü olan deal, sahibini yazmazsak yatay izolasyonun dışında
            // doğardı.
            'owner_id' => $lead->owner_id ?? $actor->getKey(),
        ]);
    }

    private function defaultDealTitle(Lead $lead, ?Company $company): string
    {
        $person = trim($lead->first_name.' '.$lead->last_name);
        $companyName = trim((string) ($company?->name ?? $lead->company_name));

        if ($companyName === '') {
            return $person;
        }

        return $person.' — '.$companyName;
    }

    /**
     * Aşamadaki mevcut en büyük `position` değerinden kesin olarak BÜYÜK bir
     * fractional index üretir (kart listenin SONUNA eklenir).
     *
     * Anahtar üretimi App\Support\FractionalIndex'e DEVREDİLMİŞTİR (Faz 7):
     * aynı mantık daha önce burada ve DemoDataSeeder'da kopyalanmıştı, Kanban
     * kart taşıma da üçüncü bir kopya isteyecekti. Sıralama anahtarının kuralı
     * (alfabe, sondaki '0' yasağı, uzunluk sınırı) tek bir yerde durmalı;
     * iki kopyanın ayrışması, aynı aşamada çakışan `position` değerleri
     * demektir.
     *
     * Soft-deleted fırsatlar da hesaba katılır: silinmiş kart geri
     * yüklendiğinde aynı position'a düşüp (stage, position) tekilliğini
     * bozmasın.
     */
    private function nextPositionInStage(int $stageId): string
    {
        /** @var string|null $max */
        $max = Deal::withTrashed()
            ->where('pipeline_stage_id', $stageId)
            ->orderByDesc('position')
            ->value('position');

        return FractionalIndex::last($max);
    }

    /**
     * Lead'e asılı morph kayıtlarını yeni Contact'a taşır.
     *
     * BU ADIM DÖNÜŞÜMÜN ASIL AMACIDIR. Yapılmazsa aramalar, toplantı notları,
     * görevler ve dosyalar lead kartında kalır; müşteri kartı bomboş açılır ve
     * satışçı "bu adamla daha önce ne konuşmuşuz" sorusunun cevabını
     * bulamaz — dönüşüm, geçmişi kopardığı için zarar verir.
     *
     * Toplu `update()` kullanılır, model model döngü DEĞİL: 50 görevi tek tek
     * kaydetmek 50 audit satırı üretir ve "aday müşteriye dönüştürüldü" tek
     * anlamlı kaydı bunların altında kaybolur. Dönüşümün izi lead'in kendi
     * audit satırında zaten duruyor.
     *
     * Soft-deleted kayıtlar da taşınır (`withTrashed`): silinmiş bir görev
     * sonradan geri yüklendiğinde artık var olmayan bir "lead bağlamına"
     * değil, doğru müşteriye düşmeli.
     */
    private function moveRelatedRecords(Lead $lead, Contact $contact): void
    {
        $leadType = $lead->getMorphClass();
        $leadId = $lead->getKey();
        $contactType = $contact->getMorphClass();
        $contactId = $contact->getKey();

        Task::withTrashed()
            ->where('taskable_type', $leadType)
            ->where('taskable_id', $leadId)
            ->update(['taskable_type' => $contactType, 'taskable_id' => $contactId]);

        Activity::withTrashed()
            ->where('activityable_type', $leadType)
            ->where('activityable_id', $leadId)
            ->update(['activityable_type' => $contactType, 'activityable_id' => $contactId]);

        Attachment::withTrashed()
            ->where('attachable_type', $leadType)
            ->where('attachable_id', $leadId)
            ->update(['attachable_type' => $contactType, 'attachable_id' => $contactId]);

        $this->moveTaggables($leadType, (int) $leadId, $contactType, (int) $contactId);
    }

    /**
     * Etiketler saf pivot tabloda (`taggables`) durur, modeli yok — query
     * builder ile taşınır.
     *
     * Dikkat: tabloda (tag_id, taggable_type, taggable_id) UNIQUE. Mevcut bir
     * Contact'a bağlanılan senaryoda kişi aynı etikete zaten sahip olabilir;
     * düz bir `update()` o satırda unique ihlali fırlatır ve TÜM dönüşümü geri
     * alır. Bu yüzden çakışan satırlar önce silinir (etiket hedefte zaten
     * duruyor, bilgi kaybı yok), kalanlar taşınır.
     */
    private function moveTaggables(string $leadType, int $leadId, string $contactType, int $contactId): void
    {
        $alreadyOnContact = DB::table('taggables')
            ->where('taggable_type', $contactType)
            ->where('taggable_id', $contactId)
            ->pluck('tag_id')
            ->all();

        $leadTags = DB::table('taggables')
            ->where('taggable_type', $leadType)
            ->where('taggable_id', $leadId);

        if ($alreadyOnContact !== []) {
            (clone $leadTags)->whereIn('tag_id', $alreadyOnContact)->delete();
        }

        $leadTags->update(['taggable_type' => $contactType, 'taggable_id' => $contactId]);
    }

    /**
     * ---------------------------------------------------------------------
     * KARAR: özel alan değerleri TAŞINMAZ, KOŞULLU olarak KOPYALANIR
     * ---------------------------------------------------------------------
     * `custom_field_values.custom_field_id` bir TANIMA işaret eder ve tanım
     * `entity_type` ile kapsanmıştır (`custom_fields` üzerinde
     * (entity_type, key) UNIQUE). Değer satırını olduğu gibi taşımak —
     * `customizable`'ı Contact yapıp `custom_field_id`'yi `leads` tanımında
     * bırakmak — şemaya göre geçerli ama ANLAMSIZ bir satır üretir: contact
     * ekranı alanlarını `entity_type = 'contacts'` ile sorgular, dolayısıyla o
     * değer hiçbir yerde GÖRÜNMEZ; buna karşılık raporlar ve silme akışları
     * onu saymaya devam eder. Görünmez ama var olan veri, en kötü türden veri.
     *
     * Bu yüzden kural şu: değer, HEDEF TARAFTA AYNI `key` ve AYNI `type` ile
     * aktif bir tanım varsa KOPYALANIR; yoksa atlanır.
     *   - `type` eşitliği aranır, çünkü `number` bir alanın değerini `date`
     *     alanına yazmak sessiz bir tip bozulmasıdır.
     *   - `select` / `multiselect` için değerin hedefin `options` listesinde
     *     bulunması ayrıca aranır; iki tarafın seçenek listeleri farklı
     *     olabilir ve listede olmayan bir değer form açılır açılmaz doğrulama
     *     hatası verirdi.
     *   - Contact'ta o alan için ZATEN bir değer varsa üzerine yazılmaz
     *     (mevcut kayda bağlanma senaryosu): müşterinin doğrulanmış verisi,
     *     lead formuna aceleyle girilmiş veriden daha güvenilirdir.
     *
     * Lead'in kendi değerleri YERİNDE KALIR (silinmez): lead dönüşüm izi
     * olarak duruyor, üzerindeki alanlar da o izin parçası.
     *
     * Not: mevcut seed'de `contacts` için hiç özel alan tanımı yok, dolayısıyla
     * bugün bu metot pratikte hiçbir şey kopyalamaz. Bu bir eksiklik değil,
     * kuralın doğru sonucu — tanım eklendiği gün kopyalama kendiliğinden
     * çalışmaya başlar.
     */
    private function copyCustomFieldValues(Lead $lead, Contact $contact): void
    {
        /** @var Collection<int, CustomFieldValue> $values */
        $values = CustomFieldValue::query()
            ->where('customizable_type', $lead->getMorphClass())
            ->where('customizable_id', $lead->getKey())
            ->with('customField')
            ->get();

        if ($values->isEmpty()) {
            return;
        }

        $sourceKeys = $values
            ->map(fn (CustomFieldValue $value): ?string => $value->customField?->key)
            ->filter()
            ->unique()
            ->all();

        if ($sourceKeys === []) {
            return;
        }

        $targets = CustomField::query()
            ->where('entity_type', self::ENTITY_CONTACTS)
            ->where('is_active', true)
            ->whereIn('key', $sourceKeys)
            ->get()
            ->keyBy('key');

        foreach ($values as $value) {
            $source = $value->customField;

            if ($source === null || $source->entity_type !== self::ENTITY_LEADS) {
                continue;
            }

            /** @var CustomField|null $target */
            $target = $targets->get($source->key);

            if ($target === null || $target->type !== $source->type) {
                continue;
            }

            if (! $this->valueFitsOptions($value->value, $target)) {
                continue;
            }

            // firstOrCreate: hedefte değer varsa dokunulmaz.
            CustomFieldValue::query()->firstOrCreate(
                [
                    'custom_field_id' => $target->getKey(),
                    'customizable_type' => $contact->getMorphClass(),
                    'customizable_id' => $contact->getKey(),
                ],
                ['value' => $value->value]
            );
        }
    }

    /**
     * Seçenekli alanlarda değerin hedefin seçenek listesine sığıp sığmadığı.
     * Seçeneksiz tiplerde (text, number, date, boolean ...) daima true.
     */
    private function valueFitsOptions(?string $value, CustomField $target): bool
    {
        if (! in_array($target->type, ['select', 'multiselect'], true)) {
            return true;
        }

        if ($value === null || $value === '') {
            return true;
        }

        $options = $target->options;

        if (! is_array($options) || $options === []) {
            return false;
        }

        $decoded = json_decode($value, true);
        $candidates = is_array($decoded) ? $decoded : [$value];

        foreach ($candidates as $candidate) {
            if (! in_array($candidate, $options, true)) {
                return false;
            }
        }

        return true;
    }
}
