<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\Auth\DeviceTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationRuleController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DealMoveController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadImportController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\Me\DeviceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PageVisitController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RoleMatrixController;
use App\Http\Controllers\Api\SavedViewController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\Sync\ManifestController;
use App\Http\Controllers\Api\Sync\PullController;
use App\Http\Controllers\Api\Sync\PushController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Sanctum SPA (cookie session) mode. The `api` middleware group already
| carries EnsureFrontendRequestsAreStateful (bootstrap/app.php ->
| withMiddleware -> statefulApi), which turns requests coming from a stateful
| domain into ordinary session requests - cookies, CSRF and all.
|
| GET /sanctum/csrf-cookie is registered by Sanctum's own service provider and
| must NOT be redeclared here.
|
*/

/*
 * Public endpoints. These never reach `active` or `password.changed`.
 */
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');

Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:6,1')
    ->name('password.forgot');

/*
 * Faz F1 — masaüstü cihaz belirteci (SYNCDESKTOP §4.3).
 *
 * Public for the same reason POST /api/login is: this is the endpoint that
 * CREATES a credential. It is not unthrottled - DeviceTokenService counts every
 * failure against the SAME keyed lockout (email + IP) the web login uses, and
 * answers 423 LOCKED_OUT. The throttle lives in the service rather than in
 * `throttle:login` middleware because a named limiter's response callback is
 * fixed at registration (429) while §4.3 requires 423, and a second named
 * limiter would create a SEPARATE counter - defeating the shared lockout.
 */
Route::post('/auth/device', [DeviceTokenController::class, 'store'])->name('auth.device');

/*
 * Faz F1 — kanal yetkilendirmesi, bearer belirteçle (protokol §3.7 / D9).
 *
 * `withBroadcasting()` is NOT called a second time in bootstrap/app.php. That
 * helper hard-codes the URI, so a second registration would produce another
 * `/broadcasting/auth`; the routes carry no name, RouteCollection returns the
 * FIRST match, and the second registration would SILENTLY never run. A quiet
 * no-op is worse than a loud failure, so the desktop channel route is declared
 * here instead, at a DIFFERENT uri: `api/broadcasting/auth`.
 *
 * Registered inside routes/api.php on purpose: ApplicationBuilder loads this
 * file inside `Route::middleware('api')->prefix('api')`, so this group inherits
 * the api stack (statefulApi + SetLocale) and the prefix for free.
 *
 * `password.changed` is DELIBERATELY ABSENT, exactly as on the cookie route -
 * see the reasoning in bootstrap/app.php: a user under a forced password change
 * still needs a live socket, since that is the session in which UserDeactivated
 * has to reach them. This is why the route appears as the fifth entry in
 * PasswordChangeGateTest's whitelist assertion.
 */
Broadcast::routes(['middleware' => ['auth:sanctum', 'active']]);

/*
 * Faz F1 — delta senkron uçları (SYNCDESKTOP §4.4, protokol §4).
 *
 * The middleware chain is the security contract, in order:
 *   auth:sanctum      identity (session OR bearer)
 *   active            a deactivated account loses access on its next request
 *   password.changed  a forced password change is not bypassable offline
 *   ability:desktop   the token must carry the `desktop` ability
 *   device.token      ...and must be a REAL PersonalAccessToken. Without this
 *                     last one the previous check is decorative: since User
 *                     gained HasApiTokens, a cookie session is handed a
 *                     TransientToken whose can() returns an unconditional true
 *                     (protocol §3.3/K-A).
 *
 * Separate throttle buckets: pull is read-only and chatty (30/min), push
 * writes and contends on the global sync counter (20/min).
 */
Route::prefix('sync')
    ->middleware(['auth:sanctum', 'active', 'password.changed', 'ability:desktop', 'device.token'])
    ->group(function () {
        Route::get('/manifest', ManifestController::class)
            ->middleware('throttle:30,1,sync')
            ->name('sync.manifest');

        Route::post('/pull', PullController::class)
            ->middleware('throttle:30,1,sync')
            ->name('sync.pull');

        Route::post('/push', PushController::class)
            ->middleware('throttle:20,1,sync-push')
            ->name('sync.push');
    });

/*
 * Authenticated endpoints.
 *
 * `active` (App\Http\Middleware\EnsureUserIsActive) runs after auth so a user
 * deactivated mid-session is rejected on their very next request - and before
 * `password.changed`, so a deactivated account gets USER_DEACTIVATED rather
 * than being told to change a password it can no longer use.
 */
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    /*
     * WHITELIST: the only endpoints exempt from `must_change_password`.
     *
     *   logout          - the single legitimate escape hatch
     *   me              - the SPA reads identity and the flag from here
     *   password/change - the flow itself
     *
     * Nothing else belongs here. `users.*` and `roles.*` are NOT exempt; the
     * change-password screen needs none of them.
     */
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me');

    // throttle:6,1 is mandatory: `current_password` is an in-session password
    // oracle and must not be left open to unlimited guessing.
    Route::post('/password/change', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1')
        ->name('password.change');

    /*
     * Everything below is subject to the forced password change.
     *
     * This is a WHITELIST by route structure, not a path-string blacklist
     * inside the middleware: a blacklist is fail-open, so every endpoint added
     * in a later phase would silently become a bypass if someone forgot to
     * list it. Here, new modules are protected by default - they simply get
     * declared inside this group.
     */
    Route::middleware('password.changed')->group(function () {
        /*
         * Kişisel arayüz tercihleri (Faz 14 / İz D) — izin GEREKTİRMEZ.
         *
         * `/settings` (settings.manage) ve `/users/{user}` (users.update) yönetici
         * yüzeyleridir; kendi dilini/para birimini seçmek her kullanıcının hakkıdır, o
         * yüzden `/me` ailesinde ayrı bir uç açıldı. Gövde yalnızca beyaz listeye karşı
         * doğrulanan iki alan taşır (UpdatePreferencesRequest); özne gövdeden değil
         * oturumdan gelir. Gerekçenin tamamı AuthController::updatePreferences()'ta.
         */
        Route::patch('/me/preferences', [AuthController::class, 'updatePreferences'])->name('me.preferences');

        /*
         * Faz F1 — kullanıcının kendi cihazları (SYNCDESKTOP §4.3).
         *
         * INSIDE the `password.changed` group on purpose (protokol §7.1): a
         * user who still owes a forced password change must not be enrolling
         * or managing devices - the temporary password is exactly the
         * credential that should not be turned into a long-lived token. This
         * also keeps PasswordChangeGateTest's whitelist untouched by these two
         * routes.
         *
         * No permission gate: these are the caller's OWN tokens, scoped through
         * $user->tokens(), and somebody else's token id answers 404.
         */
        Route::get('/me/devices', [DeviceController::class, 'index'])->name('me.devices.index');
        Route::delete('/me/devices/{token}', [DeviceController::class, 'destroy'])->name('me.devices.destroy');

        /*
         * Herkese açık güncel kur ucu (Faz 14 / İz E — docs/PHASE-INTL.md §2 Karar B) —
         * izin GEREKTİRMEZ (yalnız `auth:sanctum` + bu gruptaki `active`/`password.changed`).
         *
         * `GET /api/settings/exchange-rates` (aşağıda, `settings.manage`) yönetim ekranıdır ve
         * AYNEN kalır — bu uç onun yetkisini GEVŞETMEZ, tamamen ayrı bir amaca hizmet eder:
         * sıradan kullanıcının kendi `preferred_currency`'sinde tutar görebilmesi. Gerekçe:
         * TCMB kurları kamuya açık veridir (https://www.tcmb.gov.tr/kurlar/today.xml, kimliksiz
         * herkes okuyabilir) — gizli değildir; kullanıcının kendi tercih ettiği para biriminde
         * bir tutar görmesi bir yönetici yetkisi OLAMAZ. `ExchangeRateController::current()`.
         *
         * `throttle:30,1,exchange-rates-current` — salt-okunur, `LIMIT`li (desteklenen para
         * birimi sayısı kadar, en fazla birkaç satır), ucuz sorgu; yine de kimliği doğrulanmış
         * her kullanıcının varsayılan throttle anahtarı zaten KULLANICI bazlıdır (IP değil).
         */
        Route::get('/exchange-rates/current', [ExchangeRateController::class, 'current'])
            ->middleware('throttle:30,1,exchange-rates-current')
            ->name('exchange-rates.current');

        /*
         * Users + roles - controllers are owned by the parallel lane (C);
         * the route contract is fixed here.
         */
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');

        /*
         * Presence — the polled/first-paint view of `presence-online`.
         *
         * Inside the `password.changed` group like every other data endpoint.
         * Note the deliberate asymmetry with /broadcasting/auth, which is NOT
         * behind that gate (see bootstrap/app.php): opening a socket during a
         * forced password change is allowed, reading the roster of colleagues
         * is not. The socket is a delivery channel for the user's own
         * security events; this is other people's data.
         */
        Route::get('/presence/online', [PresenceController::class, 'online'])
            ->name('presence.online');

        /*
         * Log listeleme + dışa aktarma (Faz 5 / D).
         *
         * `logs.view` her üç listeleme ucunu, `logs.export` dışa aktarmayı
         * korur — kontrol LogController içinde Gate::allows() ile yapılır
         * (model policy yok, izin adı doğrudan kullanılır).
         *
         * `throttle:10,1,heavy-export` (H4/F3) — `/logs/export` ile
         * `/reports/export` (aşağıda) AYNI `heavy-export` önekini paylaşır:
         * ikisi de salt-okunur, sınırlı (LogQueryService::EXPORT_ROW_LIMIT /
         * DateRangeResolver::MAX_WINDOW_DAYS) ama yine de DB/CPU-ağır
         * agregasyon + dosya üretimi yapan uçlar; ortak bir "ağır iş"
         * bütçesi paylaşmaları, bir kullanıcının iki uç arasında geçiş
         * yaparak tek bir uç sınırını dolanmasını da engelliyor. Kimliği
         * doğrulanmış istekte throttle anahtarı zaten KULLANICI bazlı (bkz.
         * yukarıdaki `leads-import` notu). Dakikada 10 istek bir admin'in
         * bir inceleme oturumunda art arda birkaç export alması için
         * yeterince cömert, ama saniyede onlarca export döngüsünü engeller.
         */
        Route::get('/logs/sessions', [LogController::class, 'sessions'])->name('logs.sessions');
        Route::get('/logs/page-visits', [LogController::class, 'pageVisits'])->name('logs.page-visits');
        Route::get('/logs/activities', [LogController::class, 'activities'])->name('logs.activities');
        Route::get('/logs/export', [LogController::class, 'export'])
            ->middleware('throttle:10,1,heavy-export')
            ->name('logs.export');

        /*
         * Sayfa ziyaret takibi (Faz 5 / C) — her kimliği doğrulanmış kullanıcı
         * yalnızca kendi ziyaretini kaydeder/günceller. Controller C
         * tarafından yazılıyor; route sözleşmesi burada sabitlenir.
         */
        Route::post('/page-visits', [PageVisitController::class, 'store'])->name('page-visits.store');
        Route::patch('/page-visits/{pageVisit}/heartbeat', [PageVisitController::class, 'heartbeat'])->name('page-visits.heartbeat');

        /*
         * Leads (Faz 6 / B) — route sırası KASITLIDIR: sabit segmentli
         * uçlar (`check-duplicates`, `import`, `import/template`) parametreli
         * `{lead}` rotasından ÖNCE tanımlanmalı, yoksa Laravel bu segmentleri
         * `{lead}` route-model-binding parametresi sanıp 404 üretir.
         *
         * `import/{batch}` da `import/template`'ten SONRA gelmeli — aksi
         * halde `template` kelimesi `{batch}` parametresi sanılır.
         *
         * `throttle:5,1,leads-import` (H4/F3) — CSV içe aktarma en pahalı uç:
         * ≤500 satır senkron işlenir, üstü kuyruğa gider ama yine de her
         * istek bir dosya okuma + ayrıştırma + (kuyruklamaysa) bir job
         * dispatch'i tetikler. Kimliği doğrulanmış istekte Laravel'in
         * varsayılan throttle anahtarı zaten KULLANICI kimliğine göredir
         * (IP değil — bkz. ThrottleRequests::resolveRequestSignature), bu
         * yüzden ayrı isimli bir limiter'a gerek yok. Ayrı `leads-import`
         * öneki ile kendi bütçesini taşır; dakikada 5 istek, birkaç
         * satır hatasını düzeltip yeniden yüklemeye yetecek kadar cömert
         * ama saniyede onlarca job kuyruklayan bir döngüyü engelliyor.
         */
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::post('/leads/check-duplicates', [LeadController::class, 'checkDuplicates'])->name('leads.check-duplicates');
        Route::post('/leads/import', [LeadImportController::class, 'store'])
            ->middleware('throttle:5,1,leads-import')
            ->name('leads.import.store');
        Route::get('/leads/import/template', [LeadImportController::class, 'template'])->name('leads.import.template');
        Route::get('/leads/import/{batch}', [LeadImportController::class, 'status'])->name('leads.import.status');
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
        Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');

        /*
         * Contacts (Faz 6 / C) — controller C şeridinin; route sözleşmesi
         * burada sabitlenir. `timeline` sabit segmenti `{contact}`'tan
         * ÖNCE gelmez çünkü kendisi zaten `{contact}`'a bağlı bir alt-yol.
         */
        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
        Route::get('/contacts/{contact}/timeline', [ContactController::class, 'timeline'])->name('contacts.timeline');

        /*
         * Companies (Faz 6 / C) — controller C şeridinin; route sözleşmesi
         * burada sabitlenir.
         */
        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::patch('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
        Route::get('/companies/{company}/timeline', [CompanyController::class, 'timeline'])->name('companies.timeline');

        /*
         * Ortak (Faz 6 / B) — etiketler ve özel alan tanımları.
         */
        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::get('/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');

        /*
         * Fırsatlar / Deals (Faz 7 / B) — Kanban pano ucu + CRUD + pipeline
         * aşamaları. Controller'ları B şeridinin; `/move` ise A şeridinin
         * DealMoveController'ına bağlıdır (route sözleşmesi burada
         * sabitlenir, controller/service/request A'nın dosyaları).
         *
         * Route sırası KASITLIDIR: `/deals/board` sabit segmenti
         * `/deals/{deal}` route-model-binding parametresinden ÖNCE
         * tanımlanmalı, yoksa Laravel `board`'u bir deal id sanıp 404
         * üretir — Faz 6'da aynı tuzak `leads/check-duplicates` için
         * yaşandı (bkz. yukarı). `/deals/{deal}/move` ve `/deals/{deal}/assign`
         * ise zaten `{deal}`'e bağlı alt-yollar oldukları için bu sıra
         * sorununu YAŞAMAZ.
         */
        Route::get('/deals', [DealController::class, 'index'])->name('deals.index');
        Route::get('/deals/board', [DealController::class, 'board'])->name('deals.board');
        Route::post('/deals', [DealController::class, 'store'])->name('deals.store');
        Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
        Route::patch('/deals/{deal}', [DealController::class, 'update'])->name('deals.update');
        Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
        Route::patch('/deals/{deal}/move', [DealMoveController::class, 'update'])->name('deals.move');
        Route::patch('/deals/{deal}/assign', [DealController::class, 'assign'])->name('deals.assign');

        Route::get('/pipeline-stages', [PipelineStageController::class, 'index'])->name('pipeline-stages.index');

        /*
         * Görevler / Tasks (Faz 8 / A) — controller/service/repository A
         * şeridinin. Ticket route'ları BURADA YOK: SLA tasarımı henüz karara
         * bağlanmadığı için sonraki dalgada başka bir şerit ekleyecek.
         *
         * Route sırası KASITLIDIR: `/tasks/calendar` sabit segmenti
         * `/tasks/{task}` route-model-binding parametresinden ÖNCE
         * tanımlanmalı, yoksa Laravel `calendar`'ı bir görev id'si sanıp 404
         * üretir — Faz 6'daki `leads/check-duplicates` ve Faz 7'deki
         * `deals/board` ile AYNI tuzak (bkz. yukarıdaki yorumlar).
         * `/tasks/{task}/complete` ve `/tasks/{task}/assign` zaten `{task}`'e
         * bağlı alt-yollar oldukları için bu sıra sorununu YAŞAMAZ.
         */
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/calendar', [TaskController::class, 'calendar'])->name('tasks.calendar');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::patch('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');

        /*
         * Destek talepleri / Tickets (Faz 8 / B) — controller/service/
         * repository/SLA B şeridinin. SLA sözleşmesi docs/SLA-DESIGN.md.
         *
         * Route sırası KASITLIDIR: `/tickets/stats` sabit segmenti
         * `/tickets/{ticket}` route-model-binding parametresinden ÖNCE
         * tanımlanmalı, yoksa Laravel `stats`'ı bir ticket id'si sanıp 404
         * üretir — Faz 6'daki `leads/check-duplicates`, Faz 7'deki
         * `deals/board` ve Faz 8/A'daki `tasks/calendar` ile AYNI tuzak
         * (üç fazda üç kez yaşandı; TicketApiTest bunu doğrulayan bir test
         * taşır). `/tickets/{ticket}/status` ve `/tickets/{ticket}/assign`
         * zaten `{ticket}`'e bağlı alt-yollar oldukları için bu sıra
         * sorununu YAŞAMAZ.
         *
         * TICKET NOTLARI İÇİN AYRI UÇ YOK: notlar `activities` tablosunda
         * `type='note'` olarak tutulur ve yukarıdaki `POST /api/activities`
         * ucundan (`activityable_type: "ticket"`) eklenir — sistem kapalı
         * devredir, her not zaten iç nottur (bkz. TicketResource dokümanı).
         */
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/stats', [TicketController::class, 'stats'])->name('tickets.stats');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
        Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
        Route::patch('/tickets/{ticket}/status', [TicketController::class, 'status'])->name('tickets.status');
        Route::patch('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');

        /*
         * Aktiviteler / Activities (Faz 8 / A) — controller/service/
         * repository A şeridinin.
         */
        Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
        Route::post('/activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::get('/activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
        Route::patch('/activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');

        /*
         * Ürünler / Products (Faz 9 / B) — controller/service/repository B
         * şeridinin.
         *
         * Route sırası KASITLIDIR: `/products/categories` sabit segmenti
         * `/products/{product}` route-model-binding parametresinden ÖNCE
         * tanımlanmalı, yoksa Laravel `categories`'i bir product id'si sanıp
         * 404 üretir — Faz 6 (`leads/check-duplicates`), Faz 7
         * (`deals/board`) ve Faz 8 (`tasks/calendar`, `tickets/stats`) ile
         * AYNI tuzak (dört fazda dört kez yaşandı); ProductApiTest bunu
         * doğrulayan bir test taşır. `/products/{product}/price` zaten
         * `{product}`'a bağlı bir alt-yol olduğu için bu sıra sorununu
         * YAŞAMAZ.
         */
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/categories', [ProductController::class, 'categories'])->name('products.categories');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::get('/products/{product}/price', [ProductController::class, 'price'])->name('products.price');

        /*
         * Fiyat listeleri / Price Lists (Faz 9 / B) — controller/service/
         * repository B şeridinin. `price_list_id` teklife DEĞİL kaleme
         * bağlanır (bkz. QuoteItemController/A) — burası yalnızca fiyat
         * listesi CRUD'u ve kalem yönetimidir.
         */
        Route::get('/price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
        Route::post('/price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
        Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])->name('price-lists.show');
        Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update'])->name('price-lists.update');
        Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])->name('price-lists.destroy');
        Route::get('/price-lists/{priceList}/products', [PriceListController::class, 'products'])->name('price-lists.products');
        Route::put('/price-lists/{priceList}/products/{product}', [PriceListController::class, 'setPrice'])->name('price-lists.products.set-price');
        Route::delete('/price-lists/{priceList}/products/{product}', [PriceListController::class, 'removePrice'])->name('price-lists.products.remove-price');

        /*
         * Teklifler / Quotes (Faz 9 / A) — controller/service/repository A
         * şeridinin; route sözleşmesi burada sabitlenir (B şeridi).
         */
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
        // `calculate` sabit segmenti KASITLI OLARAK `{quote}` route-model-binding
        // parametresinden ÖNCE tanımlı — yoksa Laravel `calculate`'i bir teklif
        // id'si sanıp 404 üretir. Faz 6 (`check-duplicates`), Faz 7 (`board`),
        // Faz 8 (`calendar`, `stats`) ve Faz 9 (`categories`) ile AYNI tuzak —
        // beşinci kez. Hiçbir şey KAYDETMEZ: formda canlı toplam göstermek için
        // QuoteCalculator'ı çağırıp toplamları/tax_breakdown'ı döner (bkz.
        // docs/QUOTE-FINANCIALS.md §3) — JavaScript'te ikinci bir doğruluk
        // kaynağı olarak yeniden uygulanmasın diye.
        Route::post('/quotes/calculate', [QuoteController::class, 'calculate'])->name('quotes.calculate');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
        Route::patch('/quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
        Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
        Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
        Route::patch('/quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
        // `revise` yeni bir teklif KAYDI üretir (bir öncekinin revizyonu) —
        // bu yüzden `quotes.update` değil `quotes.create` izniyle korunur
        // (A şeridinin kararı; yetki kontrolü QuoteController::revise() içinde
        // Policy ile yapılır).
        Route::post('/quotes/{quote}/revise', [QuoteController::class, 'revise'])->name('quotes.revise');
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');

        /*
         * Bildirimler (Faz 10) — `notifications.view` izni.
         *
         * Route sırası KASITLIDIR: `/notifications/unread-count` ve
         * `/notifications/read-all` sabit segmentleri `/notifications/{notification}`
         * route-model-binding parametresinden ÖNCE tanımlanmalı, yoksa Laravel
         * bu segmentleri bir bildirim id'si sanıp 404 üretir — Faz 6'dan beri
         * tekrar eden aynı tuzak (bkz. yukarıdaki yorumlar).
         */
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        /*
         * Ayarlar (Faz 10) — `settings.manage` izni.
         *
         * Rota ADLARI sözleşmedir: `settings.pipeline-stages.index` ve
         * `settings.custom-fields.index` controller'ları `routeIs()` ile rota
         * adına bakıp yetkiyi ve `include_inactive` varsayılanını ayırıyor.
         * Mevcut `GET /api/pipeline-stages` ve `GET /api/custom-fields`
         * rotaları (yukarıda) Faz 6/7 testlerine bağlı, buradan ayrı tutulur.
         *
         * Route sırası KASITLIDIR: `/settings/pipeline-stages/reorder` sabit
         * segmenti `/settings/pipeline-stages/{stage}` route-model-binding
         * parametresinden ÖNCE tanımlanmalı — yukarıdaki tekrar eden tuzak.
         */
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('/settings/pipeline-stages', [PipelineStageController::class, 'index'])->name('settings.pipeline-stages.index');
        Route::post('/settings/pipeline-stages', [PipelineStageController::class, 'store'])->name('settings.pipeline-stages.store');
        Route::post('/settings/pipeline-stages/reorder', [PipelineStageController::class, 'reorder'])->name('settings.pipeline-stages.reorder');
        Route::patch('/settings/pipeline-stages/{stage}', [PipelineStageController::class, 'update'])->name('settings.pipeline-stages.update');

        Route::get('/settings/custom-fields', [CustomFieldController::class, 'index'])->name('settings.custom-fields.index');
        Route::post('/settings/custom-fields', [CustomFieldController::class, 'store'])->name('settings.custom-fields.store');
        Route::patch('/settings/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('settings.custom-fields.update');
        Route::delete('/settings/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('settings.custom-fields.destroy');

        Route::get('/settings/email-templates', [EmailTemplateController::class, 'index'])->name('settings.email-templates.index');
        Route::post('/settings/email-templates', [EmailTemplateController::class, 'store'])->name('settings.email-templates.store');
        Route::patch('/settings/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('settings.email-templates.update');
        Route::delete('/settings/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('settings.email-templates.destroy');

        Route::get('/settings/permission-matrix', [RoleMatrixController::class, 'index'])->name('settings.permission-matrix.index');
        Route::patch('/settings/roles/{role}/permissions', [RoleMatrixController::class, 'update'])->name('settings.roles.permissions.update');

        /*
         * Kur (döviz) — Faz 14 / İz E (docs/PHASE-INTL.md §2.1, §2.6) —
         * `settings.manage`. Yalnız OKUMA (para birimi başına en güncel kur +
         * bayatlık) ve MANUEL DÜZELTME; otomatik TCMB çekmesi bir konsol
         * komutudur (`exchange:fetch-tcmb`), HTTP ucu değildir.
         */
        Route::get('/settings/exchange-rates', [ExchangeRateController::class, 'index'])->name('settings.exchange-rates.index');
        Route::post('/settings/exchange-rates', [ExchangeRateController::class, 'store'])->name('settings.exchange-rates.store');

        /*
         * Otomasyon kuralları — Faz 14 / İz F, C4 (docs/PHASE-INTL.md §3,
         * docs/PHASE-AUDIT.md §5.1/§5.4). Yetkilendirme `AutomationRulePolicy`
         * içinde (`settings.manage` + seçilen tetikleyici/eylemin izin-eşlemesi) —
         * diğer Ayarlar uçlarının aksine burada tek satır Gate YETERSİZ, controller
         * her uçta `Gate::authorize()` ile gerçek bir Policy çağırır.
         */
        Route::get('/settings/automation-rules', [AutomationRuleController::class, 'index'])->name('settings.automation-rules.index');
        Route::post('/settings/automation-rules', [AutomationRuleController::class, 'store'])->name('settings.automation-rules.store');
        Route::patch('/settings/automation-rules/{automationRule}', [AutomationRuleController::class, 'update'])->name('settings.automation-rules.update');
        Route::delete('/settings/automation-rules/{automationRule}', [AutomationRuleController::class, 'destroy'])->name('settings.automation-rules.destroy');

        /*
         * Raporlar + Dashboard (Faz 11) — `reports.view` / `reports.export` /
         * `dashboard.view` izinleri.
         */
        Route::get('/reports/sales-performance', [ReportController::class, 'salesPerformance'])->name('reports.sales-performance');
        Route::get('/reports/user-performance', [ReportController::class, 'userPerformance'])->name('reports.user-performance');
        Route::get('/reports/source-analysis', [ReportController::class, 'sourceAnalysis'])->name('reports.source-analysis');
        Route::get('/reports/conversion', [ReportController::class, 'conversion'])->name('reports.conversion');
        // throttle:10,1,heavy-export — bkz. yukarıdaki `/logs/export` notu
        // (H4/F3): aynı önek, aynı paylaşılan "ağır iş" bütçesi.
        Route::get('/reports/export', [ReportController::class, 'export'])
            ->middleware('throttle:10,1,heavy-export')
            ->name('reports.export');

        Route::get('/dashboard/kpis', [DashboardController::class, 'kpis'])->name('dashboard.kpis');
        Route::get('/dashboard/funnel', [DashboardController::class, 'funnel'])->name('dashboard.funnel');
        Route::get('/dashboard/revenue-trend', [DashboardController::class, 'revenueTrend'])->name('dashboard.revenue-trend');
        Route::get('/dashboard/recent-activities', [DashboardController::class, 'recentActivities'])->name('dashboard.recent-activities');
        Route::get('/dashboard/task-summary', [DashboardController::class, 'taskSummary'])->name('dashboard.task-summary');

        /*
         * Sohbet / Chat (Faz 12) — `chat.use` izni, yetki ConversationPolicy /
         * MessagePolicy. Route sırası KASITLIDIR: `unread-count` ve `for-record`
         * sabit segmentlerini `{conversation}`'dan, `/messages/search` ise
         * `{message}`'tan ÖNCE tanımlanmalı.
         */
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount'])->name('conversations.unread-count');
        Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::post('/conversations/for-record', [ConversationController::class, 'forRecord'])->name('conversations.for-record');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
        Route::post('/conversations/{conversation}/members', [ConversationController::class, 'storeMember'])->name('conversations.members.store');
        Route::delete('/conversations/{conversation}/members/{user}', [ConversationController::class, 'destroyMember'])->name('conversations.members.destroy');
        Route::post('/conversations/{conversation}/leave', [ConversationController::class, 'leave'])->name('conversations.leave');
        Route::patch('/conversations/{conversation}/mute', [ConversationController::class, 'mute'])->name('conversations.mute');
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');
        Route::post('/conversations/{conversation}/delivered', [ConversationController::class, 'delivered'])->name('conversations.delivered');

        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->name('messages.store');

        Route::get('/messages/search', [MessageController::class, 'search'])->name('messages.search');
        Route::patch('/messages/{message}', [MessageController::class, 'update'])->name('messages.update');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');

        /*
         * Dosya ekleri (Faz 12) — sohbet eklerinin yüklenmesi ve servis edilmesi.
         * Görünürlük AttachmentPolicy'de: mesaja bağlı ek yalnızca o konuşmanın
         * üyelerine, bağlanmamış ek yalnızca yükleyene açıktır.
         */
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

        /*
         * Global arama / komut paleti (Faz 14 / İz F / Attio C1) —
         * `GlobalSearchService`; modül bazlı yetkilendirme (`Gate::allows
         * ('viewAny', ...)`) controller/servis katmanında yapılır, bkz.
         * `docs/PHASE-AUDIT.md` §5.4.
         *
         * `throttle:60,1,search` — bu uç bir komut paletidir ve HER TUŞ
         * VURUŞUNDA çağrılabilir (frontend'in debounce'u bu ucun
         * sorumluluğu DEĞİL; sunucu tarafı kendi başına savunmalı).
         * `leads-import`/`heavy-export` (5-10/dk) DB/CPU-ağır, TEK seferlik
         * toplu işlemlerdir — bu uç ise ucuz, salt-okunur, `LIMIT`li
         * sorgulardan oluşur (bkz. GlobalSearchService PER_MODULE_LIMIT/
         * TOTAL_LIMIT). Dakikada 60 (saniyede ortalama 1) normal bir
         * daktilo hızında yazan kullanıcıyı ASLA sınırlamaz (tipik
         * debounce'lu bir arama kutusu saniyede birden fazla istek
         * atmaz) ama saniyede onlarca isteklik bir script/döngüyü keser.
         * Kimliği doğrulanmış istekte Laravel'in varsayılan throttle
         * anahtarı zaten KULLANICI bazlıdır (IP değil).
         */
        Route::get('/search', [SearchController::class, 'index'])
            ->middleware('throttle:60,1,search')
            ->name('search.index');

        /*
         * Kayıtlı Görünümler / Saved Views (Faz 14 / İz F / Attio C2) —
         * `docs/PHASE-INTL.md` §3, güvenlik kısıtı `docs/PHASE-AUDIT.md` §5.4.
         *
         * Bu uçlar HİÇBİR ZAMAN deal/lead/... VERİSİ döndürmez — yalnızca
         * `saved_views` metadata'sını (isim/modül/saklanmış filtre) CRUD'lar.
         * Bir görünümü "uygulamak" ayrı bir uç DEĞİLDİR: frontend `index()`'ten
         * aldığı `query_json`'ı kendi URL'ine yazar, gerçek veri her zaman
         * ilgili modülün KENDİ liste ucundan (`GET /api/deals` vb.) AÇAN
         * kullanıcının kendi yetkisiyle çekilir (bkz. `SavedViewController`
         * dokümanı — "confused deputy" kısıtı). Modül bazlı yetkilendirme
         * (`.view` izni) `SavedViewPolicy` içinde yapılır; ayrı bir izin adı
         * İCAT EDİLMEDİ.
         */
        Route::get('/saved-views', [SavedViewController::class, 'index'])->name('saved-views.index');
        Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
        Route::patch('/saved-views/{savedView}', [SavedViewController::class, 'update'])->name('saved-views.update');
        Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');
    });
});
