<?php

namespace Tests\Feature;

use App\Events\DealMoved;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Faz 7 — Kanban kart taşıma (ROADMAP R4).
 *
 * Buradaki testlerin ağırlık merkezi mutlu yol değil, EŞZAMANLILIKTIR. Bir
 * Kanban panosunda aynı kartı aynı anda birden çok kişi sürükler; sessizce
 * kabul edilen bayat bir istek, başkasının hareketini geri alır ve kimse fark
 * etmez. `test_a_stale_version_is_rejected_...` bu fazın asıl testidir.
 */
class DealMoveTest extends TestCase
{
    use RefreshDatabase;

    private PipelineStage $openA;

    private PipelineStage $openB;

    private PipelineStage $wonStage;

    private PipelineStage $lostStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->openA = PipelineStage::factory()->create([
            'slug' => 'yeni-firsat', 'position' => 1, 'probability' => 10,
        ]);
        $this->openB = PipelineStage::factory()->create([
            'slug' => 'teklif-gonderildi', 'position' => 2, 'probability' => 60,
        ]);
        $this->wonStage = PipelineStage::factory()->won()->create([
            'slug' => 'kazanildi', 'position' => 3,
        ]);
        $this->lostStage = PipelineStage::factory()->lost()->create([
            'slug' => 'kaybedildi', 'position' => 4,
        ]);
    }

    private function mover(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['deals.view', 'deals.move']);

        return $user;
    }

    private function makeDeal(PipelineStage $stage, string $position, array $attributes = []): Deal
    {
        return Deal::factory()->create(array_merge([
            'pipeline_stage_id' => $stage->getKey(),
            'position' => $position,
            'version' => 1,
            'status' => 'open',
            'probability' => null,
            'closed_at' => null,
            'lost_reason' => null,
            'won_reason' => null,
        ], $attributes));
    }

    /**
     * DealCardResource'un TÜM alanlarının dolu olduğu bir kart: ilişkiler
     * yüklenmezse yanıt sessizce eksik çıkar, o yüzden şekil testleri
     * ilişkileri gerçekten olan bir kartla yapılmalı.
     */
    private function makeFullyLinkedDeal(User $owner): Deal
    {
        $company = Company::factory()->create(['name' => 'Korkmaz Tekstil']);
        $contact = Contact::factory()->create(['company_id' => $company->getKey()]);
        $tag = Tag::factory()->create();

        $deal = $this->makeDeal($this->openA, 'a0001', [
            'company_id' => $company->getKey(),
            'contact_id' => $contact->getKey(),
            'owner_id' => $owner->getKey(),
            'expected_close_date' => now()->addWeek()->toDateString(),
        ]);

        $deal->tags()->attach($tag->getKey());

        return $deal;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function move(User $actor, Deal $deal, array $body, array $headers = []): TestResponse
    {
        return $this->actingAs($actor)->patchJson(route('deals.move', ['deal' => $deal->getKey()]), $body, $headers);
    }

    /* ----------------------------------------------------------------------
     * Kimlik doğrulama / yetki
     * ------------------------------------------------------------------- */

    public function test_unauthenticated_request_is_rejected(): void
    {
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->patchJson(route('deals.move', ['deal' => $deal->getKey()]), [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ])->assertStatus(401);
    }

    public function test_user_without_deals_move_permission_is_forbidden(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(['deals.view']); // görebiliyor ama taşıyamıyor
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ])->assertStatus(403);

        $this->assertSame($this->openA->getKey(), $deal->fresh()->pipeline_stage_id);
    }

    /* ----------------------------------------------------------------------
     * Taşıma
     * ------------------------------------------------------------------- */

    public function test_moving_a_card_to_another_stage_updates_stage_position_and_version(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $response = $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ]);

        // Yanıt panonun kart şeklidir (DealCardResource); aşama id'si kartın
        // İÇİNDE değil, çünkü panoda kartlar aşama sütunlarının altında
        // gruplanır. Taşımanın gerçekten olduğunu veritabanından doğruluyoruz.
        $response->assertStatus(200)
            ->assertJsonPath('data.id', $deal->getKey())
            ->assertJsonPath('data.version', 2);

        $deal->refresh();

        $this->assertSame($this->openB->getKey(), $deal->pipeline_stage_id);
        $this->assertSame(2, $deal->version);
        $this->assertSame('open', $deal->status);
        // Hedef aşama boş: kart sona eklenir, anahtar önceki değerden farklı
        // olmak zorunda değil ama üretilmiş olmalı.
        $this->assertNotSame('', $deal->position);
        // Deal'ın kendi olasılığı boştu -> aşamanınki devralınır.
        $this->assertSame(60, $deal->probability);
    }

    public function test_a_manually_set_probability_survives_the_move(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001', ['probability' => 25]);

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        // Sürükle-bırak bir sıralama hareketidir; kullanıcının elle girdiği
        // tahmini sessizce silmez.
        $this->assertSame(25, $deal->fresh()->probability);
    }

    /* ----------------------------------------------------------------------
     * ÇAKIŞMA — bu fazın asıl testi
     * ------------------------------------------------------------------- */

    /**
     * İki kullanıcı aynı kartı aynı anda sürükler: ikisi de kartın ekranda
     * gördükleri versiyonuyla gelir. Birincisi kazanır, ikincisi 409 alır ve
     * HİÇBİR ŞEY DEĞİŞTİRMEZ.
     */
    public function test_a_stale_version_is_rejected_with_a_conflict_and_changes_nothing(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');
        $seenVersion = $deal->version;

        $first = $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => $seenVersion,
        ]);

        $first->assertStatus(200)->assertJsonPath('data.version', 2);

        $afterFirst = Deal::query()->whereKey($deal->getKey())->first()->getAttributes();

        // İkinci kullanıcı hâlâ eski versiyonu biliyor.
        $second = $this->move($actor, $deal, [
            'to_stage_id' => $this->wonStage->getKey(),
            'version' => $seenVersion,
        ]);

        $second->assertStatus(409)
            ->assertJsonPath('errors.code', 'DEAL_VERSION_CONFLICT')
            // Yanıt kartın GÜNCEL hâlini taşır: istemci refetch etmeden
            // düzeltebilmeli.
            ->assertJsonPath('deal.id', $deal->getKey())
            ->assertJsonPath('deal.version', 2)
            ->assertJsonPath('deal.status', 'open')
            // Çakışan istemcinin en kritik sorusu: kart artık HANGİ sütunda?
            // Cevap kartın kendi içinde; zarf onu ayrıca tekrarlamaz.
            ->assertJsonPath('deal.pipeline_stage_id', $this->openB->getKey())
            ->assertJsonMissingPath('pipeline_stage_id');

        $afterSecond = Deal::query()->whereKey($deal->getKey())->first()->getAttributes();

        // Reddedilen istek tek bir kolona bile dokunmamış olmalı.
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertDatabaseHas('deals', [
            'id' => $deal->getKey(),
            'pipeline_stage_id' => $this->openB->getKey(),
            'version' => 2,
            'status' => 'open',
        ]);
    }

    public function test_the_version_is_required(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, ['to_stage_id' => $this->openB->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    /* ----------------------------------------------------------------------
     * Sıralama — fractional index
     * ------------------------------------------------------------------- */

    public function test_dropping_between_two_cards_lands_between_their_positions(): void
    {
        $actor = $this->mover();
        $upper = $this->makeDeal($this->openB, 'a0010');
        $lower = $this->makeDeal($this->openB, 'a0090');
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'before_deal_id' => $upper->getKey(),
            'after_deal_id' => $lower->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        $position = $deal->fresh()->position;

        $this->assertLessThan(0, strcmp($upper->position, $position));
        $this->assertLessThan(0, strcmp($position, $lower->position));
    }

    /**
     * FRACTIONAL INDEX'İN ASIL SINAVI: 'a0001' ile 'a0002' arasında bir tamsayı
     * yoktur. Sayaç mantığı burada ya çakışır ya da tüm sütunu yeniden
     * numaralamak zorunda kalır.
     */
    public function test_dropping_between_two_adjacent_cards_still_fits(): void
    {
        $actor = $this->mover();
        $upper = $this->makeDeal($this->openB, 'a0001');
        $lower = $this->makeDeal($this->openB, 'a0002');
        $deal = $this->makeDeal($this->openA, 'b0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'before_deal_id' => $upper->getKey(),
            'after_deal_id' => $lower->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        $position = $deal->fresh()->position;

        $this->assertLessThan(0, strcmp('a0001', $position));
        $this->assertLessThan(0, strcmp($position, 'a0002'));

        // Komşuların anahtarlarına DOKUNULMAMIŞ olmalı — fractional index'in
        // vaadi tam olarak budur: tek satır UPDATE.
        $this->assertSame('a0001', $upper->fresh()->position);
        $this->assertSame('a0002', $lower->fresh()->position);
    }

    /**
     * Tek komşu gönderildiğinde eksik taraf "liste sonu" sayılmaz; hedef
     * aşamadaki gerçek komşu veritabanından tamamlanır, yoksa kart kullanıcının
     * bıraktığı yerde değil sütunun dibinde belirirdi.
     */
    public function test_a_single_neighbour_is_completed_from_the_database(): void
    {
        $actor = $this->mover();
        $upper = $this->makeDeal($this->openB, 'a0010');
        $lower = $this->makeDeal($this->openB, 'a0090');
        $deal = $this->makeDeal($this->openA, 'b0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'before_deal_id' => $upper->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        $position = $deal->fresh()->position;

        $this->assertLessThan(0, strcmp($upper->position, $position));
        $this->assertLessThan(0, strcmp($position, $lower->position));
    }

    public function test_a_neighbour_from_another_stage_is_rejected(): void
    {
        $actor = $this->mover();
        $foreign = $this->makeDeal($this->openA, 'a0050');
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'before_deal_id' => $foreign->getKey(),
            'version' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertSame($this->openA->getKey(), $deal->fresh()->pipeline_stage_id);
    }

    /**
     * Sıralama anahtarı sunucunun işidir: iki istemci aynı anda aynı boşluğa
     * bırakırsa ikisi de aynı değeri hesaplar ve sütunda çakışan iki
     * `position` oluşurdu.
     */
    public function test_a_client_supplied_position_is_rejected(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
            'position' => 'zzzz',
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertSame('a0001', $deal->fresh()->position);
    }

    /* ----------------------------------------------------------------------
     * Aşama geçiş kuralları
     * ------------------------------------------------------------------- */

    public function test_moving_to_a_lost_stage_without_a_reason_is_rejected(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->lostStage->getKey(),
            'version' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.fields.lost_reason.0', 'Kartı kayıp aşamasına taşımak için kayıp nedeni zorunludur.');

        $deal->refresh();

        $this->assertSame($this->openA->getKey(), $deal->pipeline_stage_id);
        $this->assertSame('open', $deal->status);
        $this->assertSame(1, $deal->version);
    }

    public function test_moving_to_a_lost_stage_with_a_reason_closes_the_deal(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->lostStage->getKey(),
            'version' => 1,
            'lost_reason' => 'Rakip firma tercih edildi',
        ])->assertStatus(200);

        $deal->refresh();

        $this->assertSame('lost', $deal->status);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame('Rakip firma tercih edildi', $deal->lost_reason);
        $this->assertNull($deal->won_reason);
    }

    public function test_moving_to_a_won_stage_closes_the_deal(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $this->wonStage->getKey(),
            'version' => 1,
            'won_reason' => 'Teknik ekip demosu güven verdi',
        ])->assertStatus(200);

        $deal->refresh();

        $this->assertSame('won', $deal->status);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame('Teknik ekip demosu güven verdi', $deal->won_reason);
        $this->assertNull($deal->lost_reason);
    }

    /**
     * Kart geri açıldığında eski neden YANILTICIDIR: kartı gören herkes hâlâ
     * kaybedilmiş sanır.
     */
    public function test_moving_back_to_an_open_stage_reopens_and_clears_the_reasons(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->lostStage, 'a0001', [
            'status' => 'lost',
            'closed_at' => now()->subDay(),
            'lost_reason' => 'Bütçe onaylanmadı',
        ]);

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openA->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        $deal->refresh();

        $this->assertSame('open', $deal->status);
        $this->assertNull($deal->closed_at);
        $this->assertNull($deal->lost_reason);
        $this->assertNull($deal->won_reason);
    }

    public function test_moving_to_an_inactive_stage_is_rejected(): void
    {
        $actor = $this->mover();
        $inactive = PipelineStage::factory()->inactive()->create(['slug' => 'arsiv', 'position' => 9]);
        $deal = $this->makeDeal($this->openA, 'a0001');

        $this->move($actor, $deal, [
            'to_stage_id' => $inactive->getKey(),
            'version' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertSame($this->openA->getKey(), $deal->fresh()->pipeline_stage_id);
    }

    /* ----------------------------------------------------------------------
     * Yanıt şekli — pano ile birebir aynı kart
     * ------------------------------------------------------------------- */

    /**
     * Kanban akışı şudur: pano `GET /api/deals/board` ile yüklenir -> kullanıcı
     * kartı sürükler -> sunucu kartı döner -> istemci AYNI nesneyi yerine
     * oturtur. İki uç farklı şekil döndürürse taşınan kart komşularından farklı
     * alanlara sahip olur ve `is_overdue` gibi hesaplanan alanlar taşımadan
     * sonra sessizce kaybolur.
     */
    public function test_the_move_response_carries_the_same_card_shape_as_the_board(): void
    {
        $actor = $this->mover();
        $deal = $this->makeFullyLinkedDeal($actor);

        $board = $this->actingAs($actor)->getJson(route('deals.board'));
        $board->assertStatus(200);

        $boardCard = collect($board->json('data'))
            ->flatMap(fn (array $column): array => $column['deals'])
            ->firstWhere('id', $deal->getKey());

        $this->assertNotNull($boardCard, 'Kart panoda bulunamadı.');

        $moved = $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ]);

        $moved->assertStatus(200);
        $movedCard = $moved->json('data');

        // Aynı anahtarlar, aynı sırada.
        $this->assertSame(array_keys($boardCard), array_keys($movedCard));

        // İlişkiler gerçekten çözülmüş: eager load yapılmasaydı bu alanlar
        // null/boş gelir ve şekil eşitliği yine sağlanırdı — asıl kanıt bu.
        $this->assertSame($boardCard['company'], $movedCard['company']);
        $this->assertSame($boardCard['contact'], $movedCard['contact']);
        $this->assertSame($boardCard['owner'], $movedCard['owner']);
        $this->assertSame($boardCard['tags'], $movedCard['tags']);
        $this->assertNotNull($movedCard['company']);
        $this->assertNotNull($movedCard['contact']);
        $this->assertNotNull($movedCard['owner']);
        $this->assertCount(1, $movedCard['tags']);
        $this->assertArrayHasKey('is_overdue', $movedCard);
    }

    /**
     * Çakışma yolu ayrı bir kod yolu OLMAMALI: 409, 200 ile aynı kart
     * gösterimini taşır, istemci tek bir "kartı yerine oturt" fonksiyonu yazar.
     */
    public function test_the_conflict_response_carries_the_same_card_shape_as_a_successful_move(): void
    {
        $actor = $this->mover();
        $deal = $this->makeFullyLinkedDeal($actor);

        $first = $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ]);
        $first->assertStatus(200);

        $second = $this->move($actor, $deal, [
            'to_stage_id' => $this->wonStage->getKey(),
            'version' => 1,
        ]);
        $second->assertStatus(409);

        $this->assertSame(array_keys($first->json('data')), array_keys($second->json('deal')));
        $this->assertSame($first->json('data'), $second->json('deal'));

        // Aşama bilgisi kartın kendi alanında, zarfta değil — sözleşme tek
        // kaynaklı.
        $this->assertSame($this->openB->getKey(), $second->json('deal.pipeline_stage_id'));
    }

    /**
     * N+1 kontrolü: DealCardResource dört ilişkiye dokunur (company, contact,
     * owner, tags). Eager load edilmezlerse her biri ayrı bir sorgu açar ve
     * kart başına dört fazladan gidiş-dönüş olur.
     */
    public function test_the_move_response_eager_loads_its_relations(): void
    {
        $actor = $this->mover();
        $deal = $this->makeFullyLinkedDeal($actor);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ])->assertStatus(200);

        $countFrom = function (string $table) use ($queries): int {
            return count(array_filter(
                $queries,
                fn (string $sql): bool => str_contains($sql, 'from `'.$table.'`')
            ));
        };

        $this->assertSame(1, $countFrom('companies'), 'company ilişkisi eager load edilmemiş.');
        $this->assertSame(1, $countFrom('contacts'), 'contact ilişkisi eager load edilmemiş.');
        $this->assertSame(1, $countFrom('users'), 'owner ilişkisi eager load edilmemiş.');
        $this->assertSame(1, $countFrom('tags'), 'tags ilişkisi eager load edilmemiş.');
    }

    /* ----------------------------------------------------------------------
     * Yayın
     * ------------------------------------------------------------------- */

    public function test_a_move_broadcasts_deal_moved_on_the_private_deals_channel_to_others(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001', ['owner_id' => $actor->getKey()]);
        $fromStageId = $this->openA->getKey();

        Event::fake([DealMoved::class]);

        $this->move($actor, $deal, [
            'to_stage_id' => $this->openB->getKey(),
            'version' => 1,
        ], ['X-Socket-ID' => '123456.7891011'])->assertStatus(200);

        Event::assertDispatched(DealMoved::class, function (DealMoved $event) use ($actor, $deal, $fromStageId) {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            $this->assertCount(1, $channels);
            $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
            // routes/channels.php ile sözleşme: `deals` panosu `deals.view`
            // ile korunur. İki taraftan birinde isim değişirse burada patlar.
            $this->assertSame('private-deals', (string) $channels[0]);
            $this->assertSame('deal.moved', $event->broadcastAs());

            // toOthers(): taşıyan kişinin soketi fan-out'tan çıkarılır, yoksa
            // kart optimistic update'ten sonra bir kez daha zıplar.
            $this->assertSame('123456.7891011', $event->socket);

            $this->assertSame($deal->getKey(), $payload['deal_id']);
            $this->assertSame($fromStageId, $payload['from_stage_id']);
            $this->assertSame($this->openB->getKey(), $payload['to_stage_id']);
            $this->assertSame(2, $payload['version']);
            $this->assertSame('open', $payload['status']);
            $this->assertSame($actor->getKey(), $payload['moved_by_id']);
            $this->assertSame($actor->name, $payload['moved_by_name']);
            $this->assertSame($actor->getKey(), $payload['owner_id']);
            $this->assertSame($actor->name, $payload['owner_name']);
            $this->assertNotNull($payload['position']);
            $this->assertNotNull($payload['moved_at']);

            // Payload düz skaler: kuyruk işçisi satırı yeniden sorgulamaz.
            $this->assertSame([], array_filter($payload, 'is_object'));

            return true;
        });
    }

    public function test_a_rejected_move_broadcasts_nothing(): void
    {
        $actor = $this->mover();
        $deal = $this->makeDeal($this->openA, 'a0001');

        Event::fake([DealMoved::class]);

        $this->move($actor, $deal, [
            'to_stage_id' => $this->lostStage->getKey(),
            'version' => 1,
        ])->assertStatus(422);

        Event::assertNotDispatched(DealMoved::class);
    }
}
