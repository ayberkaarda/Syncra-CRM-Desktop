<?php

namespace Tests\Feature;

use App\Events\ActivityLogged;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\ActivityLogging\PropertyTruncator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Tests\TestCase;

/**
 * Phase 5 - the audit trail.
 *
 * What is being pinned here is a CONTRACT, not an implementation: which fields
 * may appear in `activity_log.properties`, which may never appear, how big a
 * single value is allowed to get, and the exact channel + event name the SPA
 * subscribes to. Every one of those is something another module can break
 * without touching this directory - a new sensitive column, a renamed event, a
 * model that quietly starts logging - so each gets its own failure mode.
 *
 * `App\Models\Activity` (the CRM call/meeting timeline) and
 * `Spatie\Activitylog\Models\Activity` (the audit row) are two different
 * things with the same class name. The audit row is aliased `ActivityLog`
 * throughout this file.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Factories write audit rows too. Clearing the table after the arrange
     * step is what lets every assertion below talk about "the" row.
     */
    private function clearAuditLog(): void
    {
        ActivityLog::query()->delete();
    }

    private function latestAudit(): ActivityLog
    {
        $activity = ActivityLog::query()->latest('id')->first();

        $this->assertNotNull($activity, 'Beklenen audit kaydi olusmadi.');

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(ActivityLog $activity): array
    {
        return $activity->properties?->toArray() ?? [];
    }

    /* ----------------------------------------------------------------------
     * The diff
     * ------------------------------------------------------------------- */

    public function test_updating_a_deal_records_only_the_attribute_that_changed(): void
    {
        $deal = Deal::factory()->create(['amount' => 1000.00, 'status' => 'open']);
        $this->clearAuditLog();

        $deal->update(['amount' => 2500.00]);

        $activity = $this->latestAudit();
        $properties = $this->properties($activity);

        $this->assertSame('crm', $activity->log_name);
        $this->assertSame('updated', $activity->event);
        $this->assertSame('updated', $activity->description);
        $this->assertSame(Deal::class, $activity->subject_type);
        $this->assertSame($deal->id, (int) $activity->subject_id);

        // The whole point of logOnlyDirty(): `title`, `status`, `currency` and
        // the rest of $fillable did not move, so they must not be in the row.
        $this->assertSame(['amount'], array_keys($properties['attributes']));
        $this->assertSame(['amount'], array_keys($properties['old']));
        $this->assertSame('2500.00', (string) $properties['attributes']['amount']);
        $this->assertSame('1000.00', (string) $properties['old']['amount']);
    }

    /**
     * `logOnlyDirty()` treats every attribute on a brand-new row as dirty,
     * so a create writes the full state that came into being - the other
     * half of the CRUD contract this file pins for `updated`/`deleted`.
     */
    public function test_creating_a_deal_records_a_created_event_with_no_old_state(): void
    {
        $this->clearAuditLog();

        $deal = Deal::factory()->create(['amount' => 750.00, 'status' => 'open']);

        $activity = $this->latestAudit();
        $properties = $this->properties($activity);

        $this->assertSame('crm', $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame('created', $activity->description);
        $this->assertSame(Deal::class, $activity->subject_type);
        $this->assertSame($deal->id, (int) $activity->subject_id);

        // A create has no "before" - only the new state is recorded.
        $this->assertArrayHasKey('attributes', $properties);
        $this->assertArrayNotHasKey('old', $properties);
        $this->assertSame('750.00', (string) $properties['attributes']['amount']);
        $this->assertSame('open', $properties['attributes']['status']);

        // Globally excluded columns never reach the diff, creation included.
        $this->assertArrayNotHasKey('created_at', $properties['attributes']);
        $this->assertArrayNotHasKey('updated_at', $properties['attributes']);
    }

    public function test_a_save_that_changes_nothing_writes_no_history(): void
    {
        $deal = Deal::factory()->create();
        $this->clearAuditLog();

        // Re-assigning identical values. Eloquent finds nothing dirty and
        // never reaches the `updated` event at all.
        $deal->update(['title' => $deal->title, 'status' => $deal->status]);

        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_touching_a_record_writes_no_history(): void
    {
        $deal = Deal::factory()->create();
        $this->clearAuditLog();

        // touch() makes `updated_at` dirty, so the `updated` event DOES fire.
        // `updated_at` is globally excluded, which leaves an empty diff, and
        // dontSubmitEmptyLogs() is what stops the row from being written. If
        // that option is ever dropped, every save in the application starts
        // producing a content-free audit row and this test says so.
        $deal->touch();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    /* ----------------------------------------------------------------------
     * Sensitive fields
     * ------------------------------------------------------------------- */

    public function test_password_and_remember_token_never_reach_the_audit_log(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->clearAuditLog();

        $user->update([
            'name' => 'Guncellenmis Ad',
            'password' => 'Yeni!Parola2026',
        ]);
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $properties = $this->properties($this->latestAudit());

        $this->assertSame(['name'], array_keys($properties['attributes']));
        $this->assertArrayNotHasKey('password', $properties['attributes']);
        $this->assertArrayNotHasKey('remember_token', $properties['attributes']);

        // Belt and braces: not in ANY row, under any key, in either direction.
        // A hash in an audit table is still crackable credential material, and
        // the audit table is read by more people (and exported more often)
        // than `users` ever is.
        $everything = ActivityLog::query()->pluck('properties')->map(
            fn ($properties) => json_encode($properties)
        )->implode(' ');

        $this->assertStringNotContainsString('password', $everything);
        $this->assertStringNotContainsString('remember_token', $everything);
        $this->assertStringNotContainsString((string) $user->getRememberToken(), $everything);
        $this->assertStringNotContainsString($user->getAuthPassword(), $everything);
    }

    /* ----------------------------------------------------------------------
     * ROADMAP R6 - truncation
     * ------------------------------------------------------------------- */

    public function test_an_oversized_value_is_cut_and_flagged(): void
    {
        $deal = Deal::factory()->create(['description' => 'kisa aciklama']);
        $this->clearAuditLog();

        $long = str_repeat('a', 2000);
        $deal->update(['description' => $long]);

        $properties = $this->properties($this->latestAudit());
        $limit = PropertyTruncator::limit();

        $this->assertSame(1024, $limit);
        $this->assertSame($limit, mb_strlen($properties['attributes']['description']));
        $this->assertSame(str_repeat('a', $limit), $properties['attributes']['description']);

        // The marker sits BESIDE attributes/old, never inside them, so
        // Activity::changes() and any reader that predates truncation keep
        // seeing a well-formed diff.
        $this->assertArrayHasKey('attributes', $properties);
        $this->assertArrayHasKey('old', $properties);
        $this->assertSame(['description'], $properties[PropertyTruncator::MARKER]);

        // The short previous value is untouched and not flagged.
        $this->assertSame('kisa aciklama', $properties['old']['description']);

        // ...and the record itself still holds the full text. Truncation is a
        // log-size measure, never data loss.
        $this->assertSame(2000, mb_strlen($deal->fresh()->description));
    }

    public function test_truncation_is_idempotent_and_does_not_duplicate_the_marker(): void
    {
        $properties = [
            'attributes' => ['notes' => str_repeat('b', 3000)],
            'old' => ['notes' => str_repeat('c', 3000)],
        ];

        $once = PropertyTruncator::apply($properties, 100);
        $twice = PropertyTruncator::apply($once, 100);

        $this->assertSame($once, $twice);
        $this->assertSame(['notes'], $once[PropertyTruncator::MARKER]);
        $this->assertSame(100, mb_strlen($once['attributes']['notes']));
        $this->assertSame(100, mb_strlen($once['old']['notes']));
    }

    /* ----------------------------------------------------------------------
     * Causer
     * ------------------------------------------------------------------- */

    public function test_causer_is_the_authenticated_user(): void
    {
        $actor = User::factory()->create();
        $deal = Deal::factory()->create();
        $this->clearAuditLog();

        $this->actingAs($actor);
        $deal->update(['status' => 'won']);

        $activity = $this->latestAudit();

        $this->assertSame($actor->id, (int) $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);
    }

    public function test_a_write_without_an_authenticated_user_has_no_causer_but_keeps_its_context(): void
    {
        $deal = Deal::factory()->create();
        $this->clearAuditLog();

        // No actingAs(): console / queue / seeder shaped write.
        $deal->update(['status' => 'lost']);

        $activity = $this->latestAudit();
        $properties = $this->properties($activity);

        // Nobody is invented as the causer - an audit trail that guesses is
        // worse than one that admits it does not know.
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);

        // ...but the row still says where it came from, so the UI can render
        // "Sistem" instead of an empty cell.
        $this->assertArrayHasKey('_context', $properties);
        $this->assertNotSame('', $properties['_context']);
    }

    /* ----------------------------------------------------------------------
     * Deletes
     * ------------------------------------------------------------------- */

    public function test_soft_deleting_is_logged_as_a_deleted_event(): void
    {
        $deal = Deal::factory()->create();
        $this->clearAuditLog();

        $deal->delete();

        $activity = $this->latestAudit();
        $properties = $this->properties($activity);

        $this->assertTrue($deal->fresh()->trashed());
        $this->assertSame('deleted', $activity->event);
        $this->assertSame(Deal::class, $activity->subject_type);

        // A deletion records the state that was lost, not an empty "after".
        $this->assertArrayHasKey('old', $properties);
        $this->assertArrayNotHasKey('attributes', $properties);
        $this->assertSame($deal->title, $properties['old']['title']);
    }

    public function test_hard_deleting_a_model_without_soft_deletes_is_logged_as_deleted(): void
    {
        $stage = PipelineStage::factory()->create();
        $this->clearAuditLog();

        $stage->delete();

        $activity = $this->latestAudit();

        $this->assertSame('deleted', $activity->event);
        $this->assertSame(PipelineStage::class, $activity->subject_type);
        $this->assertNull(PipelineStage::query()->find($stage->id));
    }

    public function test_restoring_a_soft_deleted_record_is_logged_as_restored(): void
    {
        $deal = Deal::factory()->create();
        $deal->delete();
        $this->clearAuditLog();

        $deal->restore();

        $this->assertSame('restored', $this->latestAudit()->event);
    }

    /* ----------------------------------------------------------------------
     * Live stream
     * ------------------------------------------------------------------- */

    public function test_a_new_audit_row_broadcasts_on_private_logs_as_activity_logged(): void
    {
        $actor = User::factory()->create(['name' => 'Denetim Kullanicisi']);
        $deal = Deal::factory()->create(['title' => 'Yillik Lisans Yenileme']);
        $this->clearAuditLog();

        // Partial fake: Eloquent's own model events still reach the real
        // dispatcher, so the audit row is genuinely written - only the
        // broadcast is intercepted.
        Event::fake([ActivityLogged::class]);

        $this->actingAs($actor);
        $deal->update(['amount' => 42000.00]);

        Event::assertDispatched(ActivityLogged::class, function (ActivityLogged $event) use ($actor, $deal) {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            $this->assertCount(1, $channels);
            $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
            // Contract with routes/channels.php: `logs` is gated on
            // `logs.view`. A rename on either side must fail here rather than
            // silently publish the audit trail to a channel nobody guards.
            $this->assertSame('private-logs', (string) $channels[0]);
            $this->assertSame('activity.logged', $event->broadcastAs());

            $this->assertSame('crm', $payload['log_name']);
            $this->assertSame('updated', $payload['event']);
            $this->assertSame(Deal::class, $payload['subject_type']);
            $this->assertSame($deal->id, $payload['subject_id']);
            // Falls back to the record itself: an `updated` diff holds only
            // the dirty `amount`, so the title is not in the properties.
            $this->assertSame('Yillik Lisans Yenileme', $payload['subject_label']);
            $this->assertSame($actor->id, $payload['causer_id']);
            $this->assertSame('Denetim Kullanicisi', $payload['causer_name']);
            $this->assertNotNull($payload['created_at']);

            // The full diff is deliberately NOT on the wire - it is fanned out
            // to every `logs.view` subscriber and the stream renders one line.
            $this->assertArrayNotHasKey('properties', $payload);
            $this->assertArrayNotHasKey('attributes', $payload);

            return true;
        });
    }

    public function test_broadcast_payload_labels_a_deleted_record_from_its_own_diff(): void
    {
        $deal = Deal::factory()->create(['title' => 'Bakim Anlasmasi']);
        $this->clearAuditLog();

        Event::fake([ActivityLogged::class]);

        $deal->delete();

        Event::assertDispatched(ActivityLogged::class, function (ActivityLogged $event) {
            return $event->broadcastWith()['subject_label'] === 'Bakim Anlasmasi'
                && $event->broadcastWith()['causer_id'] === null;
        });
    }

    /* ----------------------------------------------------------------------
     * Deliberate exclusions
     * ------------------------------------------------------------------- */

    public function test_chat_messages_are_not_audited(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by' => $user->id]);
        $this->clearAuditLog();

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $message->update(['body' => 'duzenlendi']);
        $message->delete();

        // Chat is the highest-volume table in the product. Mirroring it into
        // `activity_log` would bury the handful of rows that matter under
        // thousands of "sent a message" entries, and the conversation history
        // is already fully preserved in its own table. If someone adds
        // LogsCrmActivity to Message "for completeness", this fails.
        $this->assertSame(0, ActivityLog::query()->count());
        $this->assertSame(0, ActivityLog::query()->where('subject_type', Message::class)->count());
        $this->assertSame(0, ActivityLog::query()->where('subject_type', Conversation::class)->count());
    }
}
