<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zaman çizelgesi üst kaydın (kişi/firma) görüntüleme iznini kontrol eder;
 * ilişkili kayıtların her biri ayrıca KENDİ modülünün görüntüleme iznini
 * gerektirir. Aksi halde `companies.view` taşıyan biri `tickets.view` /
 * `deals.view` olmadan o modüllerin verisini okuyabilirdi.
 */
class TimelineModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: Company, 1: Contact}
     */
    protected function seedHistory(): array
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        foreach ([[Company::class, $company->id], [Contact::class, $contact->id]] as [$type, $id]) {
            Activity::factory()->create(['activityable_type' => $type, 'activityable_id' => $id, 'subject' => 'GIZLI_AKTIVITE']);
            Task::factory()->create(['taskable_type' => $type, 'taskable_id' => $id, 'title' => 'GIZLI_GOREV']);
        }

        Ticket::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id, 'subject' => 'GIZLI_TICKET']);
        Deal::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id, 'title' => 'GIZLI_FIRSAT']);

        return [$company, $contact];
    }

    /**
     * @return array<int, string>
     */
    protected function timelineTypes(User $actor, string $url): array
    {
        $response = $this->actingAs($actor)->getJson($url)->assertOk();

        return collect($response->json('data'))->pluck('type')->unique()->sort()->values()->all();
    }

    public function test_sales_rep_does_not_see_tickets_in_company_or_contact_timeline(): void
    {
        [$company, $contact] = $this->seedHistory();
        $rep = $this->actorWithRole('Satış Temsilcisi');

        foreach (["/api/companies/{$company->id}/timeline", "/api/contacts/{$contact->id}/timeline"] as $url) {
            $response = $this->actingAs($rep)->getJson($url)->assertOk();

            $this->assertStringNotContainsString('GIZLI_TICKET', $response->getContent(), $url);
            $this->assertContains('deal', collect($response->json('data'))->pluck('type')->all(), $url);
        }
    }

    public function test_support_rep_does_not_see_deals_in_company_or_contact_timeline(): void
    {
        [$company, $contact] = $this->seedHistory();
        $support = $this->actorWithRole('Destek Temsilcisi');

        foreach (["/api/companies/{$company->id}/timeline", "/api/contacts/{$contact->id}/timeline"] as $url) {
            $response = $this->actingAs($support)->getJson($url)->assertOk();

            $this->assertStringNotContainsString('GIZLI_FIRSAT', $response->getContent(), $url);
            $this->assertContains('ticket', collect($response->json('data'))->pluck('type')->all(), $url);
        }
    }

    public function test_activity_and_task_entries_need_their_own_module_permission(): void
    {
        [$company, $contact] = $this->seedHistory();

        $actor = User::factory()->create();
        $actor->givePermissionTo(['companies.view', 'contacts.view']);

        foreach (["/api/companies/{$company->id}/timeline", "/api/contacts/{$contact->id}/timeline"] as $url) {
            $response = $this->actingAs($actor)->getJson($url)->assertOk();

            $this->assertSame([], $response->json('data'), $url);
            // Toplam da sızmamalı: kapalı modüllerin kayıt sayısı görünmez.
            $this->assertSame(0, $response->json('meta.pagination.total'), $url);
        }
    }

    public function test_full_access_role_still_sees_every_source(): void
    {
        [$company] = $this->seedHistory();
        $admin = $this->actorWithRole('Admin');

        $this->assertSame(
            ['activity', 'deal', 'task', 'ticket'],
            $this->timelineTypes($admin, "/api/companies/{$company->id}/timeline"),
        );
    }

    public function test_company_timeline_hides_contact_name_without_contacts_view(): void
    {
        [$company, $contact] = $this->seedHistory();

        $actor = User::factory()->create();
        $actor->givePermissionTo(['companies.view', 'activities.view']);

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}/timeline")->assertOk();
        $entries = collect($response->json('data'));

        // Ham içerikte arama Türkçe karakterlerin \u kaçışı yüzünden yanıltıcı
        // olurdu; kararı veren alan doğrudan okunur.
        $this->assertNotEmpty($entries);
        $this->assertSame([], $entries->pluck('meta.via_contact')->filter()->values()->all());
        $this->assertStringNotContainsString(json_encode($contact->full_name), $response->getContent());

        $actor->givePermissionTo('contacts.view');
        $named = collect($this->actingAs($actor)->getJson("/api/companies/{$company->id}/timeline")->json('data'))
            ->pluck('meta.via_contact.name')->filter()->values()->all();
        $this->assertSame([$contact->full_name], $named);
    }
}
