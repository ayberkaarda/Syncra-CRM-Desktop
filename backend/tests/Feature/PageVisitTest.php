<?php

namespace Tests\Feature;

use App\Models\PageVisitLog;
use App\Models\User;
use App\Services\Logging\PageVisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageVisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/page-visits', [
            'route' => 'dashboard',
            'path' => '/dashboard',
        ]);

        $response->assertStatus(401);
    }

    public function test_creating_a_visit_returns_201_with_id_and_persists_expected_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
            'title' => 'Kontrol Paneli',
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        $this->assertIsInt($id);

        $this->assertDatabaseHas('page_visit_logs', [
            'id' => $id,
            'user_id' => $user->id,
            'route' => 'dashboard.index',
            'path' => '/dashboard',
            'title' => 'Kontrol Paneli',
            'duration_seconds' => 0,
        ]);

        $visit = PageVisitLog::find($id);
        $this->assertNotNull($visit->entered_at);
        $this->assertNotNull($visit->last_heartbeat_at);
    }

    public function test_heartbeat_does_not_create_a_new_row_and_increases_duration(): void
    {
        $user = User::factory()->create();

        $visitId = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ])->json('data.id');

        $this->assertSame(1, PageVisitLog::count());

        $this->travel(30)->seconds();

        $response = $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat");

        $response->assertStatus(204);
        $response->assertNoContent();

        // Yeni satır AÇILMADI.
        $this->assertSame(1, PageVisitLog::count());

        $visit = PageVisitLog::find($visitId);
        $this->assertGreaterThanOrEqual(30, $visit->duration_seconds);
        $this->assertLessThan(35, $visit->duration_seconds);
    }

    public function test_heartbeat_to_another_users_visit_returns_403(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $visitId = $this->actingAs($owner)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ])->json('data.id');

        $response = $this->actingAs($attacker)->patchJson("/api/page-visits/{$visitId}/heartbeat");

        $response->assertStatus(403);

        // Kayıt değişmemiş olmalı.
        $this->assertDatabaseHas('page_visit_logs', [
            'id' => $visitId,
            'user_id' => $owner->id,
            'duration_seconds' => 0,
        ]);
    }

    /**
     * "Önceki ziyareti kapat" mantığı session_id'ye göre eşleştirir (bkz.
     * PageVisitService::start()). Bu paketteki HTTP testleri hiçbir zaman
     * gerçek bir session store taşımadığından (postJson() Sanctum'un
     * stateful "frontend" sayılması için gereken Referer/Origin header'ını
     * göndermez — bkz. test_creating_a_visit_without_a_session_store_...),
     * bu davranışı anlamlı şekilde doğrulamak için servis doğrudan, sabit
     * bir session_id ile çağrılır.
     */
    public function test_navigating_to_a_new_page_closes_the_previous_visit(): void
    {
        $user = User::factory()->create();
        $service = app(PageVisitService::class);

        $first = $service->start($user, ['route' => 'dashboard.index', 'path' => '/dashboard'], '127.0.0.1', 'same-tab-session');

        $this->travel(45)->seconds();

        $second = $service->start($user, ['route' => 'deals.index', 'path' => '/deals'], '127.0.0.1', 'same-tab-session');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, PageVisitLog::count());

        $first->refresh();
        $this->assertGreaterThanOrEqual(45, $first->duration_seconds);
        $this->assertLessThan(50, $first->duration_seconds);

        $this->assertSame(0, $second->duration_seconds);
    }

    public function test_client_supplied_duration_seconds_is_ignored(): void
    {
        $user = User::factory()->create();

        $visitId = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ])->json('data.id');

        $this->travel(10)->seconds();

        $response = $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat", [
            'duration_seconds' => 999999,
        ]);

        $response->assertStatus(204);

        $visit = PageVisitLog::find($visitId);
        $this->assertLessThan(999999, $visit->duration_seconds);
        $this->assertGreaterThanOrEqual(10, $visit->duration_seconds);
        $this->assertLessThan(15, $visit->duration_seconds);
    }

    public function test_duration_seconds_is_capped_at_eight_hours(): void
    {
        $user = User::factory()->create();

        $visitId = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ])->json('data.id');

        // 8 saatten az bir aralıkla (< 5 dk bayat eşiği) heartbeat göndererek
        // tavanı aşmaya çalış.
        $this->travel(4)->minutes();
        $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat")->assertStatus(204);

        $this->travel(4)->minutes();
        $response = $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat");
        $response->assertStatus(204);

        // Toplam geçen süre 8 dk iken tavan 28800 sn (8 saat) olduğundan
        // henüz tavana çarpmaz; burada tavanın gerçekten uygulandığını görmek
        // için doğrudan modeli tavana yakın bir değere set edip bir heartbeat
        // daha göndeririz.
        $visit = PageVisitLog::find($visitId);
        $visit->duration_seconds = 28790;
        $visit->last_heartbeat_at = now();
        $visit->save();

        $this->travel(30)->seconds();
        $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat")->assertStatus(204);

        $visit->refresh();
        $this->assertSame(28800, $visit->duration_seconds);
    }

    public function test_stale_heartbeat_gap_is_not_added_to_duration(): void
    {
        $user = User::factory()->create();

        $visitId = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ])->json('data.id');

        // Sekme 20 dk "uyudu" — bu boşluk süreye eklenmemeli.
        $this->travel(20)->minutes();

        $this->actingAs($user)->patchJson("/api/page-visits/{$visitId}/heartbeat")->assertStatus(204);

        $visit = PageVisitLog::find($visitId);
        $this->assertLessThan(300, $visit->duration_seconds);
    }

    /**
     * Regression: $request->session()->getId() koşulsuz çağrılırsa, session
     * store'un request'e bağlı olmadığı bağlamlarda (bu test dahil — TestCase
     * varsayılan postJson() çağrıları Sanctum'un stateful "frontend" tanımına
     * girecek bir Referer/Origin header'ı taşımaz, dolayısıyla StartSession
     * hiç çalışmaz) "Session store not set on request" RuntimeException'ı
     * fırlatır ve istek 500'e düşer. PageVisitController::store() artık
     * $request->hasSession() ile koruyor; bu test tam olarak bu senaryoda
     * (session YOK) isteğin düşmediğini ve session_id'nin null yazıldığını
     * doğrular.
     */
    public function test_creating_a_visit_without_a_session_store_succeeds_with_null_session_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/page-visits', [
            'route' => 'dashboard.index',
            'path' => '/dashboard',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('page_visit_logs', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'session_id' => null,
        ]);
    }

    /**
     * Düzeltme çalışan durumu bozmamalı: gerçek bir session_id verildiğinde
     * hâlâ kaydediliyor olmalı. Feature testinde HTTP üzerinden gerçek bir
     * session store kurmak (Sanctum'un "frontend" sayılması için Referer/
     * Origin header'ının config('sanctum.stateful') listesiyle eşleşmesi ve
     * bunun VerifyCsrfToken'ı pipeline'a sokması gerekiyor) bu testin kapsamı
     * dışında gereksiz kırılganlık katardı; bunun yerine PageVisitService
     * doğrudan çağrılarak (controller'ın session_id'yi nasıl elde ettiğinden
     * bağımsız olarak) servisin session_id'yi olduğu gibi yazdığı doğrulanır.
     */
    public function test_page_visit_service_persists_session_id_when_present(): void
    {
        $user = User::factory()->create();

        $visit = app(PageVisitService::class)->start(
            $user,
            ['route' => 'dashboard.index', 'path' => '/dashboard'],
            '127.0.0.1',
            'a-real-session-id',
        );

        $this->assertDatabaseHas('page_visit_logs', [
            'id' => $visit->id,
            'session_id' => 'a-real-session-id',
        ]);
    }

    /**
     * session_id null olduğunda "önceki ziyareti kapat" adımı user_id'ye tek
     * başına geri düşmemeli (bkz. PageVisitService::start() yorumu). Aksi
     * halde aynı kullanıcının session'sız iki isteği, birbiriyle hiç ilgisi
     * olmayan satırları yanlışlıkla birbirine bağlar. Bu test, session_id'siz
     * iki ardışık start() çağrısının birbirinden bağımsız iki satır
     * ürettiğini ve ilk satırın süresinin ikinci çağrıdan etkilenmediğini
     * doğrular.
     */
    public function test_null_session_id_does_not_close_an_unrelated_previous_visit(): void
    {
        $user = User::factory()->create();
        $service = app(PageVisitService::class);

        $first = $service->start($user, ['route' => 'a', 'path' => '/a'], '127.0.0.1', null);

        $this->travel(45)->seconds();

        $second = $service->start($user, ['route' => 'b', 'path' => '/b'], '127.0.0.1', null);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, PageVisitLog::count());

        $first->refresh();
        // 45 saniyelik boşluk EKLENMEDİ — ikinci start() bu satırı hiç görmedi.
        $this->assertSame(0, $first->duration_seconds);
    }
}
