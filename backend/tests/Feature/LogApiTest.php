<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PageVisitLog;
use App\Models\SessionLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Tests\TestCase;

class LogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rol/izin sözlüğünü kur — proje kuralı: ayrı test veritabanı, ana
        // syncra_crm verisine dokunulmaz (RefreshDatabase + phpunit.xml testing DB).
        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithRole(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if (! empty($extraPermissions)) {
            $user->givePermissionTo($extraPermissions);
        }

        return $user;
    }

    protected function makeActivity(array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'log_name' => 'default',
            'description' => 'Bir kayıt güncellendi',
            'event' => 'updated',
            'properties' => ['old' => ['name' => 'Eski'], 'attributes' => ['name' => 'Yeni']],
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Yetkilendirme
    // ------------------------------------------------------------------

    public function test_user_without_logs_view_permission_gets_403_on_all_three_endpoints(): void
    {
        $actor = User::factory()->create(); // rolsüz / izinsiz

        $this->actingAs($actor)->getJson('/api/logs/sessions')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/logs/page-visits')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/logs/activities')->assertStatus(403);
    }

    public function test_admin_role_lacks_logs_export_permission_and_gets_403(): void
    {
        // ROADMAP kuralı: Admin rolünde logs.view var, logs.export YOK.
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->getJson('/api/logs/export?type=sessions&format=csv');

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Listeleme — sayfalama, filtreleme, sıralama
    // ------------------------------------------------------------------

    public function test_user_with_logs_view_can_list_sessions_with_pagination_meta(): void
    {
        $actor = $this->actorWithRole('Admin');

        SessionLog::factory()->count(3)->login()->create();

        $response = $this->actingAs($actor)->getJson('/api/logs/sessions?per_page=2&page=1');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(3, $response->json('meta.pagination.total'));

        // user_agent ham stringi listeleme yanıtında dönmemeli.
        $this->assertArrayNotHasKey('user_agent', $response->json('data.0'));
    }

    public function test_date_range_filter_is_inclusive_of_boundaries(): void
    {
        $actor = $this->actorWithRole('Admin');

        $inRangeStart = SessionLog::factory()->login()->create();
        $inRangeStart->created_at = '2026-06-10 00:00:00';
        $inRangeStart->save();

        $inRangeEnd = SessionLog::factory()->login()->create();
        $inRangeEnd->created_at = '2026-06-15 23:59:59';
        $inRangeEnd->save();

        $outOfRange = SessionLog::factory()->login()->create();
        $outOfRange->created_at = '2026-06-16 00:00:01';
        $outOfRange->save();

        $response = $this->actingAs($actor)->getJson('/api/logs/sessions?filter[from]=2026-06-10&filter[to]=2026-06-15&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($inRangeStart->id));
        $this->assertTrue($ids->contains($inRangeEnd->id));
        $this->assertFalse($ids->contains($outOfRange->id));
    }

    public function test_filter_user_id_filters_sessions_correctly(): void
    {
        $actor = $this->actorWithRole('Admin');
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        SessionLog::factory()->login()->create(['user_id' => $userA->id]);
        SessionLog::factory()->login()->create(['user_id' => $userB->id]);

        $response = $this->actingAs($actor)->getJson("/api/logs/sessions?filter[user_id]={$userA->id}&per_page=100");

        $response->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('user.id');
        $this->assertTrue($userIds->every(fn ($id) => $id === $userA->id));
    }

    public function test_unknown_sort_column_falls_back_to_default_without_error(): void
    {
        $actor = $this->actorWithRole('Admin');

        SessionLog::factory()->count(2)->login()->create();

        $response = $this->actingAs($actor)->getJson('/api/logs/sessions?sort=not_a_real_column');

        $response->assertStatus(200);
    }

    public function test_unknown_sort_column_falls_back_to_default_on_activities(): void
    {
        $actor = $this->actorWithRole('Admin');

        $this->makeActivity();

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?sort=not_a_real_column');

        $response->assertStatus(200);
    }

    public function test_search_does_not_leak_across_other_filters(): void
    {
        $actor = $this->actorWithRole('Admin');
        $user = User::factory()->create();

        // q eşleşir + event=login  -> yalnızca bu satır beklenir.
        SessionLog::factory()->login()->create([
            'user_id' => $user->id,
            'email' => 'keyword-match@example.com',
        ]);

        // q eşleşir ama event=logout -> event filtresiyle elenmeli.
        SessionLog::factory()->logout()->create([
            'user_id' => $user->id,
            'email' => 'keyword-match@example.com',
        ]);

        // q eşleşmez, event=login -> q ile elenmeli.
        SessionLog::factory()->login()->create([
            'user_id' => $user->id,
            'email' => 'no-match@example.com',
        ]);

        $response = $this->actingAs($actor)->getJson('/api/logs/sessions?q=keyword-match&filter[event]=login&per_page=100');

        $response->assertStatus(200);
        $data = collect($response->json('data'));

        $this->assertCount(1, $data);
        $this->assertSame('keyword-match@example.com', $data->first()['email']);
        $this->assertSame('login', $data->first()['event']);
    }

    public function test_page_visits_listing_returns_expected_shape(): void
    {
        $actor = $this->actorWithRole('Admin');
        $user = User::factory()->create();

        PageVisitLog::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($actor)->getJson('/api/logs/page-visits?per_page=10');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.user.id', $user->id);
        $this->assertArrayHasKey('route', $response->json('data.0'));
        $this->assertArrayHasKey('path', $response->json('data.0'));
    }

    // ------------------------------------------------------------------
    // Activities — subject_type beyaz listesi, silinmiş subject
    // ------------------------------------------------------------------

    public function test_activities_subject_type_rejects_values_outside_whitelist(): void
    {
        $actor = $this->actorWithRole('Admin');

        $this->actingAs($actor)
            ->getJson('/api/logs/activities?'.http_build_query(['filter' => ['subject_type' => 'User']]))
            ->assertStatus(422);

        $this->actingAs($actor)
            ->getJson('/api/logs/activities?'.http_build_query(['filter' => ['subject_type' => 'App\\Models\\User']]))
            ->assertStatus(422);
    }

    public function test_activities_subject_type_accepts_whitelisted_short_name(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $owner->id]);

        // Company::factory()->create() modeldeki LogsCrmActivity trait'i
        // (Faz 5 / A) yüzünden kendiliğinden bir 'created' aktivitesi de
        // üretebilir — bu yüzden test satırını event=updated ile ayırt
        // edilebilir kılıyoruz.
        $this->makeActivity([
            'log_name' => 'company',
            'event' => 'updated',
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'causer_type' => User::class,
            'causer_id' => $owner->id,
        ]);

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?'.http_build_query([
            'filter' => ['subject_type' => 'company', 'event' => 'updated'],
        ]));

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertCount(1, $data);
        $entry = $data->first();
        $this->assertSame('company', $entry['subject_type']);
        $this->assertSame($company->name, $entry['subject_label']);
    }

    public function test_activity_with_deleted_subject_does_not_break_the_list(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $owner->id]);

        $this->makeActivity([
            'log_name' => 'company',
            'event' => 'updated',
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'causer_type' => User::class,
            'causer_id' => $owner->id,
        ]);

        // Projede activitylog.subject_returns_soft_deleted_models = true
        // (bkz. config/activitylog.php) — CRM'in kendi aktivite akışı
        // soft-delete edilmiş kayıtları hâlâ etiketiyle gösterebilsin diye.
        // "Subject artık hiç çözümlenemiyor" durumunu gerçekten üretmek için
        // burada kalıcı silme (forceDelete) kullanılır.
        $company->forceDelete();

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?'.http_build_query([
            'filter' => ['subject_type' => 'company', 'event' => 'updated'],
        ]));

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertCount(1, $data);
        $entry = $data->first();
        $this->assertNull($entry['subject_label']);
        $this->assertSame('company', $entry['subject_type']);
    }

    public function test_db_level_truncation_marker_passes_through_without_double_truncation(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $owner->id, 'notes' => 'kısa not']);

        // Gerçek bir update tetikler: LogsCrmActivity -> ActivityLogObserver ->
        // PropertyTruncator DB seviyesinde 1024 karaktere kırpar (ROADMAP R6).
        $this->actingAs($actor);
        $company->update(['notes' => str_repeat('A', 2000)]);

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?'.http_build_query([
            'filter' => ['subject_type' => 'company', 'event' => 'updated'],
        ]));

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertCount(1, $data);
        $entry = $data->first();

        // API kendi başına İKİNCİ bir kırpma yapmamalı (eski davranış: 200
        // karaktere kırpıp _truncated'ı boolean'a çeviriyordu). DB'nin 1024
        // sınırı AYNEN görünmeli ve _truncated bir dizi (alan adları) olmalı.
        $this->assertSame(1024, mb_strlen($entry['properties']['attributes']['notes']));
        $this->assertIsArray($entry['properties']['_truncated']);
        $this->assertContains('notes', $entry['properties']['_truncated']);
    }

    public function test_activity_response_includes_execution_context(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($actor);
        $company->update(['name' => 'Yeni İsim A.Ş.']);

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?'.http_build_query([
            'filter' => ['subject_type' => 'company', 'event' => 'updated'],
        ]));

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertCount(1, $data);
        $entry = $data->first();

        // PHPUnit süreci ActivityFormatter::context() için 'test' döner
        // (app()->runningUnitTests()) — causer'sız satırlarda kaynak ayrımı
        // için REST yanıtında da bulunmalı (önceden yalnızca broadcast'te vardı).
        $this->assertSame('test', $entry['properties']['_context']);
    }

    public function test_activities_event_filter_accepts_restored(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->getJson('/api/logs/activities?'.http_build_query([
            'filter' => ['event' => 'restored'],
        ]));

        $response->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Dışa aktarma
    // ------------------------------------------------------------------

    public function test_csv_export_starts_with_utf8_bom_and_has_correct_row_count(): void
    {
        $actor = $this->actorWithRole('Admin', ['logs.export']);

        SessionLog::factory()->count(3)->login()->create();

        $response = $this->actingAs($actor)->get('/api/logs/export?type=sessions&format=csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $withoutBom = substr($content, 3);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $withoutBom), fn ($line) => $line !== ''));

        // 1 başlık satırı + 3 veri satırı.
        $this->assertCount(4, $lines);
    }
}
