<?php

namespace Tests\Feature\Automation;

use App\Events\DealMoved;
use App\Listeners\Automation\RunAutomationRulesOnDealMoved;
use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationExecutionGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 14 / İz F — sonsuz döngü koruması (görev tanımı: "`deal.assign_owner` eylemi yeni bir
 * deal event'i doğurabilir → aynı istek içinde otomasyon zincirinin yeniden tetiklenmesini
 * engelle; basit bir 'otomasyon içinde çalışıyorum' bayrağı yeter — bunu testle kilitle").
 *
 * Bugünkü SABİT katalogla (yalnızca 3×3) gerçek bir sonsuz döngü YOLU yoktur —
 * `deal.assign_owner` yalnızca `owner_id`'yi değiştirir ve bu alan hiçbir tetikleyicinin
 * (`stage_changed`/`status_changed`/`ticket.created`) koşulu DEĞİLDİR (bkz.
 * `AutomationExecutionGuard`'ın dokümanı). Bu test, guard'ın TAM OLARAK bağlandığı yerde
 * (listener seviyesinde) İÇ İÇE (re-entrant) bir tetiklenmeyi GERÇEKTEN bastırdığını —
 * guard kaldırılırsa bu testin KIRILACAĞINI — kanıtlar; kurgusal "içeriden tekrar
 * tetikleme" senaryosu bilinçli olarak simüle edilir (savunma amaçlı korumanın kod
 * yolunu, bugün doğal olarak oluşmayan bir zincir için de kilitler).
 */
class AutomationRuleLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_reentrant_deal_moved_dispatch_during_automation_is_suppressed(): void
    {
        $creator = User::factory()->create();
        $creator->givePermissionTo(['settings.manage', 'deals.view', 'tasks.create', 'tasks.assign']);

        $owner = User::factory()->create();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStageChanged($toStage->id)
            ->taskCreateAction()
            ->create();

        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $toStage->id]);
        $mover = User::factory()->create();
        $event = new DealMoved(DealMoved::payload($deal, $fromStage->id, $mover));

        $listener = app(RunAutomationRulesOnDealMoved::class);

        // Guard ZATEN "çalışıyor" durumundayken listener'ı ÇAĞIR — bu, `deal.assign_owner`
        // gibi bir eylemin kendi içinden yeni bir Deal event'i doğurup AYNI zincire GERİ
        // DÖNMESİNİN simülasyonudur. Guard doğru bağlıysa bu çağrı NO-OP olmalı.
        AutomationExecutionGuard::run(function () use ($listener, $event): void {
            $listener->handle($event);
        });

        // İç içe (re-entrant) tetiklenme bastırılmalıydı — guard KAPALI olsaydı bu satır
        // 1 görev bulurdu (aşağıdaki "normal çağrı" testiyle KARŞILAŞTIRILDIĞINDA görünür).
        $this->assertSame(0, Task::query()->count());
        $this->assertFalse(AutomationExecutionGuard::isRunning(), 'Guard, run() bloğu bittikten sonra kapanmalı.');

        // Sanity — GUARD DIŞINDA (normal, ilk tetiklenme) AYNI event NORMAL çalışır.
        $listener->handle($event);

        $this->assertSame(1, Task::query()->count(), 'Guard dışındaki normal tetiklenme ÇALIŞMALIYDI.');
    }
}
