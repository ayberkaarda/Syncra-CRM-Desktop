<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lead yazma kuralları:
 *  - dönüşüm, mevcut bir kişiyi başka firmaya ancak `contacts.update`
 *    (ContactPolicy::update) ile taşıyabilir;
 *  - lead doğrudan `converted` durumunda oluşturulamaz (web ve senkron).
 */
class LeadWriteRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // ------------------------------------------------------------ O8

    public function test_convert_cannot_move_an_existing_contact_without_contacts_update(): void
    {
        $actor = $this->actor(['leads.view', 'leads.convert', 'contacts.view', 'companies.view']);
        $lead = Lead::factory()->create(['owner_id' => $actor->id, 'status' => 'qualified']);

        $original = Company::factory()->create();
        $target = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $original->id]);

        $this->actingAs($actor)->postJson("/api/leads/{$lead->id}/convert", [
            'company_id' => $target->id,
            'contact_id' => $contact->id,
        ])->assertForbidden();

        $this->assertSame($original->id, $contact->fresh()->company_id);
        $this->assertSame('qualified', $lead->fresh()->status, 'Reddedilen dönüşüm yarım kalmamalı.');
    }

    public function test_convert_may_link_an_existing_contact_without_moving_it(): void
    {
        $actor = $this->actor(['leads.view', 'leads.convert', 'contacts.view', 'companies.view']);
        $lead = Lead::factory()->create(['owner_id' => $actor->id, 'status' => 'qualified']);

        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $this->actingAs($actor)->postJson("/api/leads/{$lead->id}/convert", [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ])->assertOk();

        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_convert_with_contacts_update_still_moves_the_contact(): void
    {
        $rep = User::factory()->create();
        $rep->assignRole('Satış Temsilcisi');

        $lead = Lead::factory()->create(['owner_id' => $rep->id, 'status' => 'qualified']);
        $target = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($rep)->postJson("/api/leads/{$lead->id}/convert", [
            'company_id' => $target->id,
            'contact_id' => $contact->id,
        ])->assertOk();

        $this->assertSame($target->id, $contact->fresh()->company_id);
    }

    // ------------------------------------------------------------ status

    public function test_lead_cannot_be_created_as_converted(): void
    {
        $actor = $this->actor(['leads.create']);

        $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz',
            'source' => 'website',
            'status' => 'converted',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status'], 'errors.fields');

        $this->assertDatabaseCount('leads', 0);

        $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz',
            'source' => 'website',
            'status' => 'qualified',
        ])->assertCreated();
    }

    public function test_sync_push_cannot_create_a_converted_lead(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $token = $user->createToken('TEST-PC', [DeviceTokenService::ABILITY])->plainTextToken;

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [[
                'seq' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->toIso8601String(),
                'op' => 'create',
                'entity' => 'lead',
                'client_id' => (string) Str::uuid(),
                'payload' => ['first_name' => 'Çevrim', 'last_name' => 'Dışı', 'source' => 'website', 'status' => 'converted'],
            ]],
        ])->assertOk()->assertJsonPath('results.0.status', 'rejected');

        $this->assertDatabaseMissing('leads', ['status' => 'converted']);
    }
}
