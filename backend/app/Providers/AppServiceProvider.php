<?php

namespace App\Providers;

use App\Events\DealMoved;
use App\Events\TaskReminderDue;
use App\Events\TicketSlaBreached;
use App\Events\TicketSlaWarning;
use App\Http\Resources\UserResource;
use App\Listeners\Automation\RunAutomationRulesOnDealMoved;
use App\Listeners\Automation\RunAutomationRulesOnDealUpdated;
use App\Listeners\Automation\RunAutomationRulesOnTicketCreated;
use App\Listeners\Notifications\SendDealStageChangedNotification;
use App\Listeners\Notifications\SendTaskReminderNotification;
use App\Listeners\Notifications\SendTicketSlaBreachedNotification;
use App\Listeners\Notifications\SendTicketSlaWarningNotification;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SavedView;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\ActivityLogObserver;
use App\Observers\Notifications\DealNotificationObserver;
use App\Observers\Notifications\LeadNotificationObserver;
use App\Observers\Notifications\QuoteNotificationObserver;
use App\Observers\Notifications\TaskNotificationObserver;
use App\Observers\Notifications\TicketNotificationObserver;
use App\Observers\SyncDeletionObserver;
use App\Observers\SyncVersionObserver;
use App\Services\Auth\AuthService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSuperAdminGate();
        $this->registerLoginRateLimiter();
        $this->registerActivityLogObserver();
        $this->registerNotificationObservers();
        $this->registerNotificationListeners();
        $this->registerAutomationRuleListeners();
        $this->registerSyncObservers();
    }

    /**
     * Faz F1 — masaüstü senkron sürüm damgası (SYNCDESKTOP §4.2, protokol §2.2).
     *
     * Every table listed here has an Eloquent write surface, so an observer is
     * enough. `conversation_user` is DELIBERATELY ABSENT: its writes are raw
     * SQL and pivot calls that produce no model event, so it carries database
     * triggers instead (2026_09_01_100009). Observer and trigger are never
     * combined on one table - the trigger's `SET NEW.sync_version` would
     * overwrite the observer's value, spending two counter values per write and
     * tearing holes in the version space that a keyset cursor cannot see past.
     *
     * `taggables`, `quote_items` and `custom_field_values` are absent for a
     * different reason: they are not sync tables at all (protocol §1.4/§1.5),
     * they travel inside their owner's payload, and their owner is bumped by
     * App\Services\Sync\TagSyncService / App\Sync\SyncVersionBumper.
     *
     * DatabaseNotification is observed the same way ActivityLogObserver
     * observes spatie's Activity model (registerActivityLogObserver() above):
     * the class is not ours, so it cannot carry an $observers attribute, and
     * registration has to happen here.
     */
    protected function registerSyncObservers(): void
    {
        $versioned = [
            Company::class, Contact::class, Lead::class, Deal::class,
            Task::class, Activity::class, Ticket::class, Quote::class,
            Conversation::class, Message::class, Tag::class,
            PipelineStage::class, CustomField::class, Product::class,
            PriceList::class, PriceListItem::class, ExchangeRate::class,
            SavedView::class, Setting::class, User::class,
            DatabaseNotification::class,
        ];

        foreach ($versioned as $model) {
            $model::observe(SyncVersionObserver::class);
        }

        /*
         * Tombstones (protocol §2.7). Only hard-delete tables need one: a soft
         * delete already returns the row itself through the delta with
         * `deleted_at` set and a fresh version, which is strictly more
         * information than a tombstone carries.
         *
         * `conversation_user`, the third tombstone table, is covered by the
         * AFTER DELETE trigger - `detach()` issues a query-builder DELETE that
         * never reaches PHP.
         */
        Tag::observe(SyncDeletionObserver::class);
        DatabaseNotification::observe(SyncDeletionObserver::class);

        /*
         * KARAR P19 (teknik lider, F1 kapanisi) — `price_list_items` de tombstone
         * yazar.
         *
         * Protokol §2.7'nin uc tablosu (tags/notifications/conversation_user) RW
         * tarafi dusunulerek sayilmisti; RO tarafindaki tek HARD-DELETE yuzeyi
         * atlanmisti. `price_list_items` softDeletes TASIMAZ ve
         * `DELETE /api/price-lists/{list}/products/{product}` gercek bir DELETE
         * atar - tombstone olmadan istemcinin lokal aynasi HIC KUCULEMEZ ve
         * silinen bir fiyat satiri sonsuza dek yanlis fiyat gosterirdi.
         *
         * Hesaplama riski yok (`quotes.calculate` §8'de online-only), ama liste
         * ve detay ekranlari bayat fiyati gosterirdi.
         */
        PriceListItem::observe(SyncDeletionObserver::class);
    }

    /*
     * ---------------------------------------------------------------------
     * Faz 5 / B - session_logs listeners are intentionally NOT registered
     * here via Event::listen(...).
     * ---------------------------------------------------------------------
     * App\Listeners\{LogSuccessfulLogin,LogSuccessfulLogout,LogFailedLogin,
     * LogLockout} exist, but AuthService (app/Services/Auth/AuthService.php)
     * calls their log() methods directly instead of relying on Laravel's
     * automatic auth event dispatch, because none of the four events behave
     * the way an auto-registered listener would need. (Their public methods
     * are named `log()`, not `handle()`, specifically so Laravel's automatic
     * event discovery - which auto-wires any `handle*`/`__invoke` method
     * under app/Listeners to the event type-hinted as its first parameter,
     * with no Event::listen() call needed - does not pick them up too.)
     *   - Login:   SessionGuard::login() regenerates the session AND fires
     *              the real Login event BEFORE AuthService's own, second,
     *              explicit session()->regenerate() call - an automatic
     *              listener would capture a session id that gets
     *              invalidated one line later.
     *   - Logout:  fires at a safe point, but is called directly anyway for
     *              wiring symmetry with the other three (see
     *              LogSuccessfulLogout's docblock).
     *   - Failed:  never fires - AuthService uses guard->validate(), and
     *              SessionGuard::validate() does not call fireFailedEvent()
     *              (only attempt()/attemptWhen()/once() do).
     *   - Lockout: never fires - it is only dispatched by the
     *              Illuminate\Foundation\Auth\ThrottlesLogins trait, and
     *              this app throttles logins via the NAMED rate limiter
     *              below (registerLoginRateLimiter()) instead.
     * Adding Event::listen() mappings here as well would either do nothing
     * (Failed/Lockout never fire) or double-write a row per request
     * (Login/Logout), so this provider deliberately registers none.
     */

    /**
     * Audit trail (Phase 5).
     *
     * Registered here rather than in an EventServiceProvider $observers map
     * because the observed class is not ours: it is whatever
     * `config('activitylog.activity_model')` resolves to. Asking the package
     * keeps this correct if that config key is ever pointed at a custom model.
     *
     * What the observer does - diff truncation (ROADMAP R6), execution-context
     * stamping and the `private-logs` broadcast - is documented on
     * App\Observers\ActivityLogObserver.
     */
    protected function registerActivityLogObserver(): void
    {
        $activityModel = ActivitylogServiceProvider::determineActivityModel();

        $activityModel::observe(ActivityLogObserver::class);
    }

    /**
     * Faz 10 — model bazlı bildirim tetikleyicileri (A grubu, bkz. görev
     * sözleşmesi "TETİKLEYİCİLER — KRİTİK KURAL"). Deal/Task/Ticket/Lead/
     * Quote servis/repository katmanlarına TEK BİR dispatch satırı bile
     * eklenmedi; her observer ilgili modelin `created`/`updated` Eloquent
     * event'lerine burada bağlanır.
     */
    protected function registerNotificationObservers(): void
    {
        Deal::observe(DealNotificationObserver::class);
        Task::observe(TaskNotificationObserver::class);
        Ticket::observe(TicketNotificationObserver::class);
        Lead::observe(LeadNotificationObserver::class);
        Quote::observe(QuoteNotificationObserver::class);
    }

    /**
     * Faz 10 — mevcut event'lere bağlanan bildirim listener'ları (B grubu).
     * `DealMoved`/`TaskReminderDue`/`TicketSlaWarning`/`TicketSlaBreached`
     * event'lerinin kendileri Faz 7/8'de zaten üretiliyor; burada yalnızca
     * onlara YENİ bir dinleyici eklenir, üretildikleri servisler
     * değiştirilmez.
     */
    protected function registerNotificationListeners(): void
    {
        Event::listen(DealMoved::class, SendDealStageChangedNotification::class);
        Event::listen(TaskReminderDue::class, SendTaskReminderNotification::class);
        Event::listen(TicketSlaWarning::class, SendTicketSlaWarningNotification::class);
        Event::listen(TicketSlaBreached::class, SendTicketSlaBreachedNotification::class);
    }

    /**
     * Faz 14 / İz F — C4 küçük no-code otomasyon kuralları
     * (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1/§5.4).
     *
     * ÜÇ tetikleyici de mevcut Eloquent/broadcast olaylarına bağlanır, YENİ bir paralel
     * mekanizma kurulmaz:
     *   - `deal.stage_changed`  → `DealMoved`  (Faz 7'de zaten yayınlanıyor, Faz 10'un
     *     `SendDealStageChangedNotification`'ı ile AYNI event, ikinci bağımsız dinleyici).
     *   - `deal.status_changed` → Eloquent'in HER `Deal` güncellemesinde kendiliğinden
     *     fırlattığı ham `"eloquent.updated: ".Deal::class` olayı — `Deal::observe()`'un
     *     ALTINDA yatan AYNI mekanizma (bkz. RunAutomationRulesOnDealUpdated dokümanı).
     *   - `ticket.created`      → aynı desenin `Ticket` + `created` karşılığı.
     */
    protected function registerAutomationRuleListeners(): void
    {
        Event::listen(DealMoved::class, RunAutomationRulesOnDealMoved::class);
        Event::listen('eloquent.updated: '.Deal::class, RunAutomationRulesOnDealUpdated::class);
        Event::listen('eloquent.created: '.Ticket::class, RunAutomationRulesOnTicketCreated::class);
    }

    /**
     * The `Super Admin` role holds ZERO permission rows on purpose - its
     * authority comes from here, so a newly added permission is granted to it
     * automatically without a migration or a re-seed.
     */
    protected function registerSuperAdminGate(): void
    {
        Gate::before(function ($user, string $ability) {
            // MUST return null (not false) for everyone else. Returning false
            // here would short-circuit Gate and deny EVERY ability for EVERY
            // non-super-admin, bypassing all policies and permission checks.
            return $user->hasRole(UserResource::SUPER_ADMIN_ROLE) ? true : null;
        });
    }

    /**
     * Named limiter used by `throttle:login` on POST /api/login.
     *
     * 5 attempts per window, and the window itself grows exponentially on
     * consecutive lockouts (1 -> 2 -> 4 -> 8 ... capped at 60 minutes), so a
     * brute-force run gets progressively more expensive while an honest user
     * who mistyped their password twice is barely inconvenienced.
     *
     * The key mixes e-mail and IP - see AuthService::throttleKey() for why
     * neither one alone is acceptable.
     */
    protected function registerLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = AuthService::throttleKey(
                (string) $request->input('email'),
                $request->ip()
            );

            return Limit::perMinutes(
                AuthService::lockoutMinutes($key),
                AuthService::MAX_LOGIN_ATTEMPTS
            )
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    // $headers already carries Retry-After + X-RateLimit-*.
                    return response()->json([
                        'errors' => [
                            'message' => 'Çok fazla başarısız giriş denemesi. Lütfen bir süre sonra tekrar deneyin.',
                            'code' => 'TOO_MANY_ATTEMPTS',
                        ],
                    ], 429, $headers);
                });
        });
    }
}
