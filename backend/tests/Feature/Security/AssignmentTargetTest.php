<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atama ve sahip alanları yalnızca AKTİF ve SİLİNMEMİŞ bir kullanıcıyı kabul
 * eder. Düz `exists:users,id` devre dışı bırakılmış ya da silinmiş hesabı da
 * geçiriyordu; kayıt kimsenin göremeyeceği bir hesaba düşüyordu.
 */
class AssignmentTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    /**
     * @return array<string, User>
     */
    private function targets(): array
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $deleted = User::factory()->create();
        $deleted->delete();

        return ['inactive' => $inactive, 'deleted' => $deleted, 'active' => User::factory()->create()];
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> uri, method, field
     */
    private function endpoints(): array
    {
        $deal = Deal::factory()->create();
        $lead = Lead::factory()->create(['status' => 'new']);
        $task = Task::factory()->create();
        $ticket = Ticket::factory()->create();

        return [
            ["/api/deals/{$deal->id}/assign", 'PATCH', 'owner_id'],
            ["/api/leads/{$lead->id}/assign", 'PATCH', 'owner_id'],
            ["/api/tasks/{$task->id}/assign", 'PATCH', 'assigned_to'],
            ["/api/tickets/{$ticket->id}/assign", 'PATCH', 'assigned_to'],
        ];
    }

    public function test_assign_endpoints_refuse_inactive_and_deleted_users(): void
    {
        $targets = $this->targets();

        foreach ($this->endpoints() as [$uri, $method, $field]) {
            foreach (['inactive', 'deleted'] as $kind) {
                $this->actingAs($this->admin)->json($method, $uri, [$field => $targets[$kind]->id])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field], 'errors.fields');
            }

            $this->actingAs($this->admin)->json($method, $uri, [$field => $targets['active']->id])->assertOk();
        }
    }

    public function test_create_endpoints_refuse_inactive_owner_or_assignee(): void
    {
        $inactive = $this->targets()['inactive'];
        $stage = PipelineStage::factory()->create();
        $contact = Contact::factory()->create();

        $cases = [
            ['/api/companies', ['name' => 'Yeni A.Ş.', 'owner_id' => $inactive->id], 'owner_id'],
            ['/api/contacts', ['first_name' => 'Ali', 'last_name' => 'Veli', 'owner_id' => $inactive->id], 'owner_id'],
            ['/api/leads', ['first_name' => 'Ali', 'last_name' => 'Veli', 'source' => 'website', 'owner_id' => $inactive->id], 'owner_id'],
            ['/api/deals', ['title' => 'F', 'amount' => 1, 'pipeline_stage_id' => $stage->id, 'contact_id' => $contact->id, 'owner_id' => $inactive->id], 'owner_id'],
            ['/api/tasks', ['title' => 'G', 'priority' => 'medium', 'assigned_to' => $inactive->id], 'assigned_to'],
            ['/api/tickets', ['subject' => 'T', 'description' => 'D', 'priority' => 'medium', 'contact_id' => $contact->id, 'assigned_to' => $inactive->id], 'assigned_to'],
        ];

        foreach ($cases as [$uri, $body, $field]) {
            $this->actingAs($this->admin)->postJson($uri, $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field], 'errors.fields');
        }
    }

    public function test_editing_a_record_whose_owner_was_deactivated_still_works_when_owner_is_resent(): void
    {
        $former = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $former->id]);
        $contact = Contact::factory()->create(['owner_id' => $former->id]);
        $former->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->patchJson("/api/companies/{$company->id}", [
            'name' => 'Yeni Ad',
            'owner_id' => $former->id,
        ])->assertOk();

        $this->actingAs($this->admin)->patchJson("/api/contacts/{$contact->id}", [
            'first_name' => 'Yeni',
            'owner_id' => $former->id,
        ])->assertOk();

        $this->assertSame('Yeni Ad', $company->fresh()->name);
        $this->assertSame($former->id, $company->fresh()->owner_id);

        // Ama başka bir kaydı o hesaba DEVRETMEK hâlâ reddedilir.
        $other = Company::factory()->create();
        $this->actingAs($this->admin)->patchJson("/api/companies/{$other->id}", ['owner_id' => $former->id])
            ->assertUnprocessable();
    }
}
