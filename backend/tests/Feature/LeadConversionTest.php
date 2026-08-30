<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Leads\LeadConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 6 — lead → müşteri dönüşümü.
 *
 * Bu servisin her hatası KALICIDIR: yanlış oluşturulmuş bir Contact silinse
 * bile lead'in `converted_*` izleri kalır, yarım kalmış bir dönüşüm ise
 * kullanıcıyı ikinci kez dönüştürmeye iter ve çift müşteri kaydı üretir.
 * Testler bu yüzden "mutlu yol"dan çok sınır durumlara bakıyor: ikinci kez
 * dönüştürme, eksik firma adı, mevcut kayda bağlanma, geçmişin taşınması ve
 * ortada patlayan bir işlemin HİÇBİR iz bırakmaması.
 */
class LeadConversionTest extends TestCase
{
    use RefreshDatabase;

    private LeadConversionService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeadConversionService;
        $this->actor = User::factory()->create();
    }

    /**
     * Kanban aşamaları seed'den gelir; testte de gerçek sıralamayı taklit eden
     * en az iki aşama gerekir, yoksa "en küçük position" iddiası boş kalır.
     *
     * @return array<string, PipelineStage>
     */
    private function makeStages(): array
    {
        return [
            'first' => PipelineStage::factory()->create(['name' => 'Yeni Fırsat', 'slug' => 'yeni-firsat', 'position' => 1]),
            'second' => PipelineStage::factory()->create(['name' => 'İletişim Kuruldu', 'slug' => 'iletisim-kuruldu', 'position' => 2]),
        ];
    }

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'first_name' => 'Selin',
            'last_name' => 'Korkmaz',
            'email' => 'selin.korkmaz@ornekmail.com',
            'phone' => '+90 532 444 55 66',
            'company_name' => 'Korkmaz Tekstil',
            'position' => 'Satın Alma Müdürü',
            'status' => 'qualified',
            'owner_id' => $this->actor->id,
            'notes' => 'Fuarda tanışıldı, bütçe onaylı.',
            'converted_at' => null,
            'converted_contact_id' => null,
            'converted_company_id' => null,
            'converted_deal_id' => null,
        ], $attributes));
    }

    public function test_conversion_creates_contact_company_and_deal_and_marks_the_lead(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        $result = $this->service->convert($lead, [
            'create_deal' => true,
            'deal_amount' => 125000.0,
        ], $this->actor);

        $contact = $result['contact'];
        $company = $result['company'];
        $deal = $result['deal'];

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertInstanceOf(Company::class, $company);
        $this->assertInstanceOf(Deal::class, $deal);

        // Kişi lead'den birebir kopyalanır; `notes` de taşınır çünkü çoğu zaman
        // müşteriyle ilgili tek bağlam bilgisi odur.
        $this->assertSame('Selin', $contact->first_name);
        $this->assertSame('Korkmaz', $contact->last_name);
        $this->assertSame('selin.korkmaz@ornekmail.com', $contact->email);
        $this->assertSame('+90 532 444 55 66', $contact->phone);
        $this->assertSame('Satın Alma Müdürü', $contact->position);
        $this->assertSame('Fuarda tanışıldı, bütçe onaylı.', $contact->notes);
        $this->assertSame($this->actor->id, $contact->owner_id);
        $this->assertSame($company->id, $contact->company_id);

        $this->assertSame('Korkmaz Tekstil', $company->name);
        $this->assertSame($this->actor->id, $company->owner_id);

        $this->assertSame('Selin Korkmaz — Korkmaz Tekstil', $deal->title);
        $this->assertSame('125000.00', (string) $deal->amount);
        $this->assertSame('open', $deal->status);
        $this->assertSame(1, $deal->version);
        $this->assertSame($contact->id, $deal->contact_id);
        $this->assertSame($company->id, $deal->company_id);
        $this->assertSame($this->actor->id, $deal->owner_id);

        // Lead SİLİNMEZ — müşterinin nereden geldiğinin tek kaydı odur.
        $lead->refresh();
        $this->assertFalse($lead->trashed());
        $this->assertSame('converted', $lead->status);
        $this->assertNotNull($lead->converted_at);
        $this->assertSame($contact->id, $lead->converted_contact_id);
        $this->assertSame($company->id, $lead->converted_company_id);
        $this->assertSame($deal->id, $lead->converted_deal_id);
    }

    /**
     * Adı olmayan bir Company kaydı ("Adsız Firma") listeleri kirletir ve
     * sonradan temizlenmesi gereken çöp veri üretir.
     */
    public function test_no_company_is_created_when_the_lead_has_no_company_name(): void
    {
        $this->makeStages();
        $lead = $this->makeLead(['company_name' => null]);

        $result = $this->service->convert($lead, ['create_deal' => false], $this->actor);

        $this->assertNull($result['company']);
        $this->assertNull($result['contact']->company_id);
        $this->assertSame(0, Company::query()->count());

        $lead->refresh();
        $this->assertNull($lead->converted_company_id);
    }

    public function test_no_deal_is_created_when_create_deal_is_false(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        $result = $this->service->convert($lead, ['create_deal' => false], $this->actor);

        $this->assertNull($result['deal']);
        $this->assertSame(0, Deal::query()->count());

        $lead->refresh();
        $this->assertNull($lead->converted_deal_id);
    }

    /**
     * Sessizce kabul etmek (mevcut contact'ı geri döndürmek) çift müşteri
     * kaydı üretir. 422 tek doğru cevaptır.
     */
    public function test_an_already_converted_lead_cannot_be_converted_twice(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        $this->service->convert($lead, ['create_deal' => false], $this->actor);

        $contactsAfterFirst = Contact::query()->count();
        $companiesAfterFirst = Company::query()->count();

        try {
            $this->service->convert($lead, ['create_deal' => false], $this->actor);
            $this->fail('İkinci dönüşüm reddedilmedi.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('lead', $exception->errors());
        }

        $this->assertSame($contactsAfterFirst, Contact::query()->count(), 'İkinci dönüşüm ikinci bir kişi üretti.');
        $this->assertSame($companiesAfterFirst, Company::query()->count(), 'İkinci dönüşüm ikinci bir firma üretti.');
    }

    /**
     * Servis yalnızca `status`'e değil `converted_at`'e de bakar: iki alandan
     * biri elle bozulsa bile kapı kapalı kalmalı.
     */
    public function test_a_lead_with_only_converted_at_set_is_also_rejected(): void
    {
        $lead = $this->makeLead(['status' => 'qualified', 'converted_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service->convert($lead, ['create_deal' => false], $this->actor);
    }

    /**
     * Duplicate tespiti "bu firma / bu kişi zaten var" dediğinde kullanılan
     * yol: YENİ kayıt açılmaz, mevcut olana bağlanılır.
     */
    public function test_existing_company_and_contact_are_reused_instead_of_creating_new_ones(): void
    {
        $this->makeStages();
        $company = Company::factory()->create(['name' => 'Mevcut Firma']);
        $contact = Contact::factory()->create(['company_id' => null]);
        $lead = $this->makeLead();

        $result = $this->service->convert($lead, [
            'create_deal' => false,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ], $this->actor);

        $this->assertSame($company->id, $result['company']->id);
        $this->assertSame($contact->id, $result['contact']->id);
        $this->assertSame(1, Company::query()->count(), 'Yeni firma oluşturuldu.');
        $this->assertSame(1, Contact::query()->count(), 'Yeni kişi oluşturuldu.');

        // Kişi firmasızdı; dönüşüm onu seçilen firmaya bağlamalı.
        $contact->refresh();
        $this->assertSame($company->id, $contact->company_id);
    }

    /**
     * DÖNÜŞÜMÜN ASIL AMACI. Taşınmazsa iletişim geçmişi lead kartında kalır,
     * müşteri kartı bomboş açılır ve satışçı "bu kişiyle ne konuşmuştuk"
     * sorusuna cevap bulamaz.
     */
    public function test_tasks_activities_attachments_and_tags_move_to_the_new_contact(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        Task::factory()->count(3)->create([
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);

        Activity::factory()->count(2)->create([
            'activityable_type' => $lead->getMorphClass(),
            'activityable_id' => $lead->id,
        ]);

        Attachment::factory()->create([
            'attachable_type' => $lead->getMorphClass(),
            'attachable_id' => $lead->id,
        ]);

        $tag = Tag::factory()->create();
        DB::table('taggables')->insert([
            'tag_id' => $tag->id,
            'taggable_type' => $lead->getMorphClass(),
            'taggable_id' => $lead->id,
        ]);

        $result = $this->service->convert($lead, ['create_deal' => false], $this->actor);
        $contact = $result['contact'];

        // Sayı korunur — taşıma, kopyalama ya da silme değil.
        $this->assertSame(3, Task::query()->count());
        $this->assertSame(2, Activity::query()->count());

        $this->assertSame(3, Task::query()
            ->where('taskable_type', $contact->getMorphClass())
            ->where('taskable_id', $contact->id)
            ->count());
        $this->assertSame(2, Activity::query()
            ->where('activityable_type', $contact->getMorphClass())
            ->where('activityable_id', $contact->id)
            ->count());
        $this->assertSame(1, Attachment::query()
            ->where('attachable_type', $contact->getMorphClass())
            ->where('attachable_id', $contact->id)
            ->count());
        $this->assertSame(1, DB::table('taggables')
            ->where('taggable_type', $contact->getMorphClass())
            ->where('taggable_id', $contact->id)
            ->count());

        // Lead tarafında hiçbir morph kaydı kalmamalı.
        $this->assertSame(0, Task::query()
            ->where('taskable_type', $lead->getMorphClass())
            ->where('taskable_id', $lead->id)
            ->count());
        $this->assertSame(0, Activity::query()
            ->where('activityable_type', $lead->getMorphClass())
            ->where('activityable_id', $lead->id)
            ->count());
        $this->assertSame(0, DB::table('taggables')
            ->where('taggable_type', $lead->getMorphClass())
            ->where('taggable_id', $lead->id)
            ->count());
    }

    /**
     * `taggables` üzerinde (tag_id, taggable_type, taggable_id) UNIQUE. Mevcut
     * bir kişiye bağlanırken kişi aynı etikete zaten sahipse düz bir taşıma
     * unique ihlali fırlatır ve TÜM dönüşümü geri alırdı.
     */
    public function test_duplicate_tag_rows_do_not_break_the_conversion(): void
    {
        $contact = Contact::factory()->create();
        $lead = $this->makeLead();
        $tag = Tag::factory()->create();

        DB::table('taggables')->insert([
            ['tag_id' => $tag->id, 'taggable_type' => $lead->getMorphClass(), 'taggable_id' => $lead->id],
            ['tag_id' => $tag->id, 'taggable_type' => $contact->getMorphClass(), 'taggable_id' => $contact->id],
        ]);

        $this->service->convert($lead, [
            'create_deal' => false,
            'contact_id' => $contact->id,
        ], $this->actor);

        $this->assertSame(1, DB::table('taggables')
            ->where('taggable_type', $contact->getMorphClass())
            ->where('taggable_id', $contact->id)
            ->count());
        $this->assertSame(0, DB::table('taggables')
            ->where('taggable_type', $lead->getMorphClass())
            ->where('taggable_id', $lead->id)
            ->count());
    }

    /**
     * Aşama HARD-CODE EDİLMEZ: ilk aşama pasifleştirilirse fırsat, kalan
     * aktif aşamaların position'ı en küçük olanına düşmeli.
     */
    public function test_deal_lands_in_the_active_stage_with_the_smallest_position(): void
    {
        PipelineStage::factory()->create(['position' => 1, 'slug' => 'pasif-ilk', 'is_active' => false]);
        $expected = PipelineStage::factory()->create(['position' => 2, 'slug' => 'aktif-ikinci', 'is_active' => true]);
        PipelineStage::factory()->create(['position' => 3, 'slug' => 'aktif-ucuncu', 'is_active' => true]);

        $lead = $this->makeLead();

        $result = $this->service->convert($lead, ['create_deal' => true], $this->actor);

        $this->assertSame($expected->id, $result['deal']->pipeline_stage_id);
        $this->assertSame('0.00', (string) $result['deal']->amount, 'deal_amount verilmeyince 0 olmalı.');
    }

    /**
     * Yeni kart aşamanın SONUNA eklenir ve (stage, position) tekilliği bozulmaz.
     */
    public function test_new_deal_position_sorts_after_the_existing_cards_in_the_stage(): void
    {
        $stages = $this->makeStages();

        $existing = Deal::factory()->create([
            'pipeline_stage_id' => $stages['first']->id,
            'position' => 'a0020',
        ]);

        $lead = $this->makeLead();

        $result = $this->service->convert($lead, ['create_deal' => true], $this->actor);
        $deal = $result['deal'];

        $this->assertSame($stages['first']->id, $deal->pipeline_stage_id);
        $this->assertGreaterThan($existing->position, $deal->position);
        $this->assertNotSame($existing->position, $deal->position);
    }

    /**
     * ROLLBACK. Dönüşümün ortasında patlayan bir işlem GERİYE HİÇBİR ŞEY
     * bırakmamalı: sahipsiz bir Contact + hâlâ "qualified" görünen bir lead,
     * kullanıcıyı ikinci kez dönüştürmeye iter ve çift müşteri kaydı üretir.
     *
     * Hata, Contact ve Company oluşturulduktan SONRA fırlatılıyor (Deal
     * `creating` event'i) — yani transaction gerçekten iş yapmışken.
     */
    public function test_a_failure_midway_rolls_back_every_write(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        Deal::creating(function (): void {
            throw new RuntimeException('Fırsat oluşturulurken beklenmedik hata.');
        });

        try {
            $this->service->convert($lead, ['create_deal' => true], $this->actor);
            $this->fail('Beklenen hata fırlatılmadı.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fırsat oluşturulurken beklenmedik hata.', $exception->getMessage());
        }

        $this->assertSame(0, Contact::query()->withTrashed()->count(), 'Rollback sonrası kişi kaldı.');
        $this->assertSame(0, Company::query()->withTrashed()->count(), 'Rollback sonrası firma kaldı.');
        $this->assertSame(0, Deal::query()->withTrashed()->count(), 'Rollback sonrası fırsat kaldı.');

        $lead->refresh();
        $this->assertSame('qualified', $lead->status, 'Lead rollback sonrası dönüşmüş görünüyor.');
        $this->assertNull($lead->converted_at);
        $this->assertNull($lead->converted_contact_id);
    }

    /**
     * Rollback testinin bir şey KANITLADIĞININ kanıtı: aynı senaryo hata
     * fırlatılmadan çalıştığında kayıtlar gerçekten oluşuyor. Bu olmadan
     * yukarıdaki test, servis hiçbir şey yapmasa da yeşil kalırdı.
     */
    public function test_the_same_scenario_without_a_failure_does_write(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        $this->service->convert($lead, ['create_deal' => true], $this->actor);

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, Deal::query()->count());
    }

    /**
     * Sahipsiz lead'de owner boş bırakılmaz — `$actor` devreye girer, yoksa
     * dönüşen müşteri hiç kimseye ait olmaz ve sahip bazlı filtrelerde kaybolur.
     */
    public function test_actor_becomes_the_owner_when_the_lead_has_none(): void
    {
        $lead = $this->makeLead(['owner_id' => null]);

        $result = $this->service->convert($lead, ['create_deal' => false], $this->actor);

        $this->assertSame($this->actor->id, $result['contact']->owner_id);
        $this->assertSame($this->actor->id, $result['company']->owner_id);
    }

    public function test_custom_deal_title_overrides_the_generated_one(): void
    {
        $this->makeStages();
        $lead = $this->makeLead();

        $result = $this->service->convert($lead, [
            'create_deal' => true,
            'deal_title' => 'Korkmaz Tekstil — CRM Lisansı',
        ], $this->actor);

        $this->assertSame('Korkmaz Tekstil — CRM Lisansı', $result['deal']->title);
    }
}
