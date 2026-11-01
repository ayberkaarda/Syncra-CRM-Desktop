<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biçimsiz girdi 422 almalı, 500 değil:
 *  - skaler beklenen alana dizi (`email[]=`, `from[]=`, `report[]=`);
 *  - firma `website` alanında http/https dışındaki şemalar.
 */
class InputShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('reports.export');

        return $user;
    }

    public function test_login_with_array_email_is_422(): void
    {
        $this->postJson('/api/login', ['email' => ['a@example.com'], 'password' => 'x'])
            ->assertUnprocessable();
    }

    public function test_report_and_dashboard_date_arrays_are_422(): void
    {
        $admin = $this->admin();

        $urls = [
            '/api/dashboard/kpis?from[]=2026-01-01',
            '/api/dashboard/funnel?to[]=2026-01-01',
            '/api/dashboard/revenue-trend?from[]=2026-01-01&group_by[]=month',
            '/api/reports/sales-performance?from[]=2026-01-01',
            '/api/reports/user-performance?to[]=2026-01-01',
            '/api/reports/source-analysis?from[x]=1',
            '/api/reports/conversion?to[]=1',
        ];

        foreach ($urls as $url) {
            $this->actingAs($admin)->getJson($url)->assertUnprocessable();
        }
    }

    public function test_report_export_array_report_or_format_is_422(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/reports/export?report[]=sales-performance')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/reports/export?report=sales-performance&format[]=csv')->assertUnprocessable();
    }

    public function test_company_website_accepts_only_http_and_https(): void
    {
        $admin = $this->admin();

        foreach (['javascript:alert(1)', 'file:///C:/Windows/win.ini', 'ftp://example.com', 'data:text/html,x'] as $bad) {
            $this->actingAs($admin)->postJson('/api/companies', ['name' => 'Kötü', 'website' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['website'], 'errors.fields');
        }

        $company = Company::factory()->create();
        $this->actingAs($admin)->patchJson("/api/companies/{$company->id}", ['website' => 'javascript:alert(1)'])
            ->assertUnprocessable();

        $this->actingAs($admin)->postJson('/api/companies', ['name' => 'İyi', 'website' => 'https://example.com'])->assertCreated();
        $this->actingAs($admin)->patchJson("/api/companies/{$company->id}", ['website' => 'http://example.com'])->assertOk();
    }
}
