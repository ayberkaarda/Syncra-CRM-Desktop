# AUTH-FLOWS — İlk Girişte Zorunlu Şifre Değişimi (`must_change_password`)

> **Statü: BAĞLAYICI SÖZLEŞME.** Bu doküman, `must_change_password` dayatma akışını
> implemente edecek şeritler için tek doğruluk kaynağıdır. Buradaki imzalar, hata
> kodları ve dosya listesi tartışmasızdır; sapma gerekirse önce bu doküman güncellenir.
>
> Kapsam: Faz 2 tamamlayıcısı. Tarih: 2026-08-23.

---

## 1. Karar Özeti

**Dayatma sunucuda, route-yapısal beyaz listeli bir middleware ile yapılır; frontend
yalnızca UX katmanıdır.** Yeni bir `EnsurePasswordIsChanged` middleware'i (alias:
`password.changed`), `auth:sanctum` + `active` grubunun İÇİNDEKİ bir alt gruba uygulanır;
yalnızca `POST /api/logout`, `GET /api/me` ve yeni `POST /api/password/change` bu alt
grubun dışında kalır. Bayrağı taşıyan kullanıcının diğer tüm isteklerine
**403 `PASSWORD_CHANGE_REQUIRED`** dönülür. Yeni endpoint mevcut şifreyi ister
(oturum çalınması / başıboş bırakılmış ekran senaryosuna karşı), başarıda bayrağı
temizler, session id'yi yeniler ve remember token'ı döndürür (rotate eder). Kullanıcının
**diğer** oturumları, stack'te zaten aktif olan Sanctum `AuthenticateSession`
middleware'i sayesinde şifre hash'i değişince bir sonraki isteklerinde otomatik düşer —
ayrıca bir mekanizma yazılmaz. Frontend'de kapatılamaz bir modal değil, `AppLayout`
dışında ayrı bir `/change-password` route'u kullanılır; `RequireAuth` bayrağı görünce
her korumalı route'tan oraya yönlendirir ve tek kaçış logout'tur.

Bu tasarım `EnsureUserIsActive` ile bilinçli olarak simetriktir: her istekte hidrate
edilmiş user modelinden okunan bir boolean, kanonik tek bir 403 zarfı, Redis session
enumerasyonuna bağımlı olmayan senkron dayatma.

---

## 2. Tehdit Modeli

**Engellenen risk:** Kapalı devre modelde her hesabın ilk şifresini yönetici üretir ve
bir kanaldan (sözlü, e-posta, not) kullanıcıya iletir. Dayatma olmadan bu geçici şifre
fiilen kalıcıdır; sonuç:

1. **Yönetici, kullanıcının kalıcı şifresini bilir** — hesap sahipliği / inkar
   edilemezlik (non-repudiation) çöker: `activity_logs`/`session_logs` kayıtları "bu
   işlemi yalnızca o kullanıcı yapmış olabilir" iddiasını taşıyamaz.
2. **İletim kanalı sızarsa** (not kağıdı, e-posta kutusu, omuz sörfü) hesap süresiz ele
   geçirilmiş olur; zorunlu değişim, geçici şifrenin ömrünü tek girişe indirir.
3. Yönetici üretimli şifreler desen tekrarı taşımaya meyillidir; zorunlu değişim +
   `Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()` politikası
   bunu keser.

**Frontend-only çözüm neden yetersiz:** SPA guard'ı yalnızca tarayıcı içi bir
yönlendirmedir. Sanctum cookie session'ı olan herkes API'ye doğrudan istek atabilir
(curl, Postman, DevTools, kaydedilmiş fetch). `RequireAuth` içine konan bir redirect,
`GET /api/users` çağrısını sunucuda durdurmaz — kullanıcı şifresini hiç değiştirmeden
tüm veriye erişir. Bu, `EnsureUserIsActive`'in var olma gerekçesiyle aynıdır: **güvenlik
sınırı her zaman sunucudur; frontend yalnızca kullanıcıyı doğru ekrana taşır.**

**Bu akışın çözmediği şeyler** (bilinçli): geçici şifrenin iletim kanalının güvenliği
(organizasyonel konu), oturum çerezi çalınması (HttpOnly + SameSite + CSRF katmanı
ayrıca ele alır), şifre geçmişi/rotasyon (Kapsam Dışı, §7).

---

## 3. Backend Sözleşmesi

### 3.1 Yeni endpoint: `POST /api/password/change`

- **Middleware:** `auth:sanctum`, `active`, `throttle:6,1` — **`password.changed` DEĞİL** (muaf).
  - `throttle:6,1` şarttır: `current_password` alanı, oturum içi bir şifre doğrulama
    oracle'ıdır; sınırsız denemeye açık bırakılmaz.
- **İstek gövdesi** (`App\Http\Requests\Auth\ChangePasswordRequest`):

```json
{
  "current_password": "string",
  "password": "string",
  "password_confirmation": "string"
}
```

- **Kurallar:**
  - `current_password`: `['required', 'string', 'current_password:web']` — hata mesajı:
    `"Mevcut şifreniz hatalı."`
  - `password`: `['required', 'string', 'confirmed', 'different:current_password',
    Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()]`
    (mevcut politikayla birebir aynı tanım).
- **Mevcut şifre İSTENİR — gerekçe:** Kullanıcı geçici şifreyle zaten giriş yapmış olsa
  da, bu endpoint (a) bayraksız kullanıcıların gönüllü şifre değişimi için de kalıcı
  sözleşmedir (Faz 10 profil ekranı aynı endpoint'i kullanacak), (b) başıboş bırakılmış
  bir ekranda ya da çalınmış bir session cookie ile oturan saldırganın, gerçek
  kullanıcıyı kalıcı olarak kilitleyip hesabı devralmasını engeller. Sürtünme maliyeti
  tek bir input alanıdır; iki ayrı endpoint/kural seti taşımaktan ucuzdur. **Karar: tek
  endpoint, her zaman `current_password` zorunlu.**
- **Başarı yanıtı:** `200 OK`, gövde `GET /api/me` ile aynı zarf:
  `{ "data": { ...UserResource, "must_change_password": false } }`.
  (Frontend bu gövdeyi doğrudan `setUser()`'a basar; ek `/me` çağrısı gerekmez.)
- **Hata yanıtları:**
  - `422` `VALIDATION_ERROR` — `fields.current_password` / `fields.password` dolu
    (yanlış mevcut şifre dahil; ayrı bir kod YOK, mevcut zarf yeterli).
  - `429` `TOO_MANY_ATTEMPTS` — throttle.
  - `401` `UNAUTHENTICATED`, `403` `USER_DEACTIVATED`, `419` `CSRF_TOKEN_MISMATCH` —
    mevcut genel davranış aynen geçerli.

### 3.2 Sunucu tarafı işlem sırası — `AuthService::changePassword(User $user, string $password, Request $request): User`

Sıra bağlayıcıdır:

1. `$user->password = $password;` (model cast `'password' => 'hashed'` hash'ler)
2. `$user->must_change_password = false;`
3. `$user->setRememberToken(Str::random(60));`
4. `$user->save();`
5. `$request->session()->regenerate();` (session fixation koruması — login'deki kuralın aynısı)
6. `Log::info('Şifre değiştirildi.', ['user_id', 'ip', 'was_forced' => bool])` —
   Faz 5'te `session_logs`/audit'e bağlanır.
7. `return $user->refresh()->load('roles');`

**Not:** Adım 5 sonrası CSRF token durumu ne olursa olsun SPA'daki mevcut 419-retry
interceptor'ı tek denemede kendiliğinden toparlar; ek iş yok.

### 3.3 Session / remember-token / diğer oturumlar

- **Session regenerate: EVET** (adım 5). Şifre değişimi bir yetki sınırıdır.
- **Remember token: HER ŞİFRE DEĞİŞİMİNDE ROTATE EDİLİR** (adım 3). Geçici şifre
  dönemine ait "beni hatırla" çerezleri hiçbir cihazda yaşamaya devam edemez. Mevcut
  oturum session cookie ile sürdüğü için kullanıcı atılmaz; remember'ı bir sonraki
  login'de yeniden kurar.
- **Diğer oturumlar: EK KOD YAZILMAZ — mekanizma zaten var.** `config/sanctum.php` →
  `middleware.authenticate_session` = `Laravel\Sanctum\Http\Middleware\AuthenticateSession`
  tanımlı ve `EnsureFrontendRequestsAreStateful::frontendMiddleware()` bunu stateful
  istek zincirine dahil ediyor (vendor kaynak kodunda doğrulandı). Bu middleware, login
  anında session'a yazılan `password_hash_web` değerini kullanıcının güncel hash'iyle
  her istekte karşılaştırır; uyuşmazlıkta session'ı flush edip 401 fırlatır. Yani şifre
  değişince kullanıcının **diğer** tüm oturumları bir sonraki isteklerinde
  `401 UNAUTHENTICATED` alır ve SPA onları login'e düşürür. Değişimi yapan oturum ise
  middleware'in yanıt-sonrası fazında güncel hash'i kendi session'ına yazdığı için
  hayatta kalır. Redis session'ları user_id ile taranamadığından (bkz. `UserDeactivated`
  açıklaması) bu tembel (lazy) model buradaki DOĞRU modeldir; "anında tüm oturumları
  sil" hedefi kapsam dışıdır (§7).

### 3.4 Middleware: `EnsurePasswordIsChanged`

- **Dosya:** `backend/app/Http/Middleware/EnsurePasswordIsChanged.php` (YENİ)
- **Alias:** `password.changed` — `backend/bootstrap/app.php` →
  `$middleware->alias([...])` bloğuna `'active'` yanına eklenir.
- **Davranış:** `$request->user()` null değilse ve `must_change_password` true ise
  `AuthService::passwordChangeRequiredResponse()` döner (aşağıda); değilse `$next`.
  `EnsureUserIsActive` gibi ek DB sorgusu YOK — guard'ın hidrate ettiği modelden okunur.
  `EnsureUserIsActive`'ten farkı: **oturumu SONLANDIRMAZ** (logout/invalidate yok) —
  kullanıcının kimliği geçerlidir, yalnızca erişimi kısıtlıdır.
- **Kanonik yanıt** — `AuthService`'e statik metod eklenir (`deactivatedResponse()` kalıbı):

```json
HTTP 403
{
  "errors": {
    "message": "Devam etmeden önce geçici şifrenizi değiştirmeniz gerekiyor.",
    "code": "PASSWORD_CHANGE_REQUIRED"
  }
}
```

- **HTTP kodu gerekçesi:** 403 — kimlik doğrulanmış (401 yanlış), kaynak mevcut (404
  yanlış), istek biçimi geçerli (422 yanlış); erişim koşullu olarak reddediliyor.
  `USER_DEACTIVATED` ile aynı kalıp: 403 + ayırt edici `code`.

### 3.5 Muafiyet listesi — BEYAZ LİSTE, route yapısıyla

**Beyaz liste seçildi; kara liste REDDEDİLDİ.** Kara liste ("yalnız şu route'ları
engelle") fail-open'dır: Faz 3+ her yeni endpoint'te "listeye ekledin mi?" disiplini
gerektirir ve unutulan her endpoint bir bypass olur. Beyaz listede yeni endpoint'ler
otomatik korunur (fail-safe / secure-by-default).

Muafiyet, middleware içinde path-string karşılaştırmasıyla DEĞİL, **route grubu
yapısıyla** kurulur (tek doğruluk kaynağı `routes/api.php` olur, path drift riski sıfır):

```php
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // must_change_password'dan MUAF çekirdek (beyaz liste):
    Route::post('/logout', ...)->name('logout');
    Route::get('/me', ...)->name('me');
    Route::post('/password/change', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1')->name('password.change');

    // Geri kalan HER ŞEY bayrağa tabidir:
    Route::middleware('password.changed')->group(function () {
        // users.*, roles.* ve gelecekteki TÜM modüller buraya
    });
});
```

- **Muafiyetler ve gerekçeleri:**
  - `POST /api/password/change` — akışın kendisi.
  - `GET /api/me` — SPA açılışta kimliği ve bayrağı buradan okur.
  - `POST /api/logout` — tek meşru kaçış yolu.
  - `GET /sanctum/csrf-cookie`, `POST /api/login`, `POST /api/password/forgot` — zaten
    auth grubunun dışında; middleware'e hiç uğramazlar, doğal muaf.
  - **BAŞKA MUAFİYET YOK.** Özellikle `GET /api/roles` ve `GET/POST/PATCH/DELETE
    /api/users...` muaf DEĞİLDİR; şifre değiştirme ekranı bunların hiçbirine ihtiyaç
    duymaz.
- **Sıra:** `auth:sanctum` → `active` → `password.changed`. Pasif hesap, şifre
  dayatmasından ÖNCE atılır; `password/change` muaf grubunda bile `active` çalışır
  (pasif kullanıcı şifre de değiştiremez).

### 3.6 Değişecek / yeni backend dosyaları

| Dosya | İşlem |
|---|---|
| `backend/app/Http/Middleware/EnsurePasswordIsChanged.php` | YENİ — §3.4 |
| `backend/app/Http/Requests/Auth/ChangePasswordRequest.php` | YENİ — §3.1 kuralları; `authorize(): true` (auth middleware halleder) |
| `backend/app/Http/Controllers/Api/AuthController.php` | `changePassword(ChangePasswordRequest $request): JsonResponse` eklenir — login/me kalıbında ince controller |
| `backend/app/Services/Auth/AuthService.php` | `changePassword()` (§3.2) + `passwordChangeRequiredResponse()` (§3.4) eklenir |
| `backend/bootstrap/app.php` | alias: `'password.changed' => EnsurePasswordIsChanged::class` |
| `backend/routes/api.php` | §3.5 grup yapısı + yeni route |
| `backend/tests/Feature/Auth/ChangePasswordTest.php` | YENİ — §6 kabul kriterlerini kapsayan feature testleri |
| `backend/app/Services/Users/UserService.php` | **DEĞİŞMEZ** — `resetPassword()` mevcut haliyle doğrudur (§5.2) |

---

## 4. Frontend Sözleşmesi

### 4.1 Route / guard yapısı

- **Ayrı route: `/change-password` — modal DEĞİL.** Gerekçe: modal `AppLayout` içinde
  render edilir; navigasyon/sidebar görünür kalır, kapatma/DevTools-silme/refresh
  yarışları üretir. Ayrı route, `LoginPage` gibi `AppLayout` DIŞINDA tam sayfa render
  edilir: ekranda navigasyon yoktur, kaçış affordance'ı yoktur; gerçek dayatma zaten
  sunucudadır.
- `frontend/src/router.tsx`:

```tsx
{
  path: '/change-password',
  element: (
    <RequireAuth>
      <ChangePasswordPage />
    </RequireAuth>
  ),
},
```

- `RequireAuth` değişikliği (`frontend/src/features/auth/components/RequireAuth.tsx`):
  mevcut `unauthenticated → /login` kontrolünden SONRA, permission kontrolünden ÖNCE:

```tsx
const user = useAuthStore((state) => state.user)

if (
  status === 'authenticated' &&
  user?.must_change_password &&
  location.pathname !== '/change-password'
) {
  return <Navigate to="/change-password" replace state={{ from: location }} />
}
```

- `ChangePasswordPage` kendi içinde ters guard taşır: `status === 'authenticated'` ve
  `!user.must_change_password` ise `<Navigate to="/" replace />`. (Gönüllü şifre
  değişimi UI'ı bu turda YOK — Faz 10 profil ekranı, §7.)

### 4.2 Ekran davranışı — `frontend/src/features/auth/pages/ChangePasswordPage.tsx` (YENİ)

- Alanlar: "Mevcut (geçici) şifre", "Yeni şifre", "Yeni şifre (tekrar)" — üçü de
  `type="password"`; `autocomplete` sırasıyla `current-password`, `new-password`,
  `new-password`.
- Şifre politikası metni form üzerinde SÜREKLİ görünür (en az 12 karakter, büyük/küçük
  harf, rakam, sembol) — kullanıcı 422 yiyerek öğrenmesin.
- Gönderim: `getCsrfCookie()` çağrısı GEREKMEZ (oturum açık, cookie mevcut); doğrudan
  `POST /api/password/change`. Başarıda: `useAuthStore.getState().setUser(data)` →
  toast "Şifreniz güncellendi." → `navigate(state?.from ?? '/', { replace: true })`.
- Hata gösterimi: `errors.fields.*` alan altı mesajlara; `429 TOO_MANY_ATTEMPTS` toast
  + submit'i `Retry-After` süresince disable; diğer durumlarda `errors.message` form
  üstü hata kutusunda.
- **Kaçış yolları:** ekranda YALNIZCA "Çıkış yap" ikincil aksiyonu vardır
  (`POST /api/logout` → `store.clear()` → `/login`). Başka link/nav yok. Tarayıcı geri
  tuşu veya elle URL girişi `RequireAuth` tarafından geri yakalanır (§4.3). Sayfa
  yenilense de `/api/me` bayrağı yine `true` döndürür ve akış buraya döner.

### 4.3 Kullanıcı doğrudan başka URL'e giderse

- **Adres çubuğuna `/users` yazarsa:** SPA açılışı `/api/me` ile store'u doldurur →
  `RequireAuth` bayrağı görür → daha hiçbir veri isteği atılmadan `/change-password`'e
  `replace` ile yönlendirilir. Hedef, `state.from` olarak taşınır; değişim sonrası
  kullanıcı oraya düşer.
- **Guard atlanır / bayat client state ile bir API çağrısı sızarsa:** sunucu
  `403 PASSWORD_CHANGE_REQUIRED` döner. `frontend/src/lib/axios.ts` interceptor'ına
  yeni dal eklenir: `status === 403 && code === 'PASSWORD_CHANGE_REQUIRED'` →
  `registerUnauthorizedHandler` kalıbıyla kaydedilen yeni bir
  `registerPasswordChangeHandler` callback'i tetiklenir →
  `router.navigate('/change-password')`. **Store TEMİZLENMEZ** — oturum geçerlidir;
  `USER_DEACTIVATED`/401 dallarından farkı budur. Handler kaydı
  `frontend/src/router.tsx` → `registerAuthRedirect()` yanında yapılır.
- **`/change-password`'e bayraksız gelinirse:** sayfa içi ters guard `/`'a yollar (§4.1).

### 4.4 Değişecek / yeni frontend dosyaları

| Dosya | İşlem |
|---|---|
| `frontend/src/features/auth/pages/ChangePasswordPage.tsx` | YENİ — §4.2 |
| `frontend/src/features/auth/components/RequireAuth.tsx` | bayrak yönlendirmesi — §4.1 |
| `frontend/src/router.tsx` | `/change-password` route'u + `registerPasswordChangeHandler` kaydı |
| `frontend/src/lib/axios.ts` | `PASSWORD_CHANGE_REQUIRED` dalı + handler registry — §4.3 |
| `frontend/src/features/auth/api/authApi.ts` | `changePassword({ current_password, password, password_confirmation })` çağrısı |
| `frontend/src/features/auth/types.ts` | **DEĞİŞMEZ** — `must_change_password` alanı zaten mevcut |

---

## 5. Kenar Durum Kararları

### 5.1 Super Admin ilk kurulum

- Seeder'ın Super Admin'i `must_change_password: true` ile yaratması **DOĞRUDUR ve
  KORUNUR.** Seeder şifresi repo/dokümantasyon/`.env` üzerinden bilinebilir; ilk girişte
  değiştirtmek tam olarak bu akışın amacıdır. `Gate::before` izin bypass'ı middleware'i
  ETKİLEMEZ (bu bir Gate/Policy değildir); Super Admin de ekranı görür.
- Şifre değişince bayrak `changePassword` içinde temizlenir (§3.2 adım 2). Ayrı bir
  "bayrak temizleme" endpoint'i YOKTUR ve eklenmez; bayrağı temizleyen TEK yol başarılı
  şifre değişimidir.

### 5.2 Yönetici `resetPassword` sonrası hedef kullanıcının açık oturumu

- `UserService::resetPassword()` şu anki haliyle (şifre + `must_change_password=true` +
  remember token rotate, session'a dokunmadan) **DOĞRUDUR — değişiklik gerekmez.**
  Gerekçe: stateful zincirdeki `AuthenticateSession` (§3.3), hedef kullanıcının tüm
  açık oturumlarını bir sonraki isteklerinde `password_hash_web` uyuşmazlığından 401
  ile düşürür; SPA onları login'e taşır ve yeni geçici şifreyle girişte bayrak devreye
  girer. Bu, `is_active` ile aynı "tembel ama her istekte senkron" güvenlik modelidir.
- İmplementasyon şeridi bu davranışı feature testiyle SABİTLEMELİDİR (§6/A9): garanti
  uygulama kodundan değil Sanctum konfigürasyonundan geliyor; `config/sanctum.php`'den
  `authenticate_session` satırını kaldıran bir refactor bu garantiyi sessizce yok eder.
  Test, o regresyonun alarmıdır.

### 5.3 Yeni şifre = eski şifre

- `different:current_password` kuralıyla ENGELLENİR (§3.1). `current_password` kuralı
  alanın doğruluğunu zaten garanti ettiğinden düz metin karşılaştırma yeterlidir; ek
  `Hash::check` gerekmez. Şifre geçmişi (son N şifre yasağı) bu turda YOK (§7).

---

## 6. Kabul Kriterleri

Backend (feature testleri — `backend/tests/Feature/Auth/ChangePasswordTest.php`):

- **A1.** `must_change_password=true` kullanıcı `GET /api/users` çağırırsa: `403`,
  gövde `{"errors":{"message":"...","code":"PASSWORD_CHANGE_REQUIRED"}}`; oturum
  SONLANMAZ (hemen ardından `GET /api/me` 200 döner).
- **A2.** Aynı kullanıcı `GET /api/me`, `POST /api/logout` ve `POST /api/password/change`
  endpoint'lerinden 403 `PASSWORD_CHANGE_REQUIRED` ALMAZ.
- **A3.** Geçerli gövdeyle `POST /api/password/change`: `200`,
  `data.must_change_password === false`; DB'de bayrak `false`, şifre hash'i değişmiş,
  remember token değişmiş, session id istek öncesine göre farklı (regenerate).
- **A4.** Değişim sonrası aynı oturumla `GET /api/users`: `200` (dayatma kalkar).
- **A5.** Yanlış `current_password`: `422 VALIDATION_ERROR`, `fields.current_password` dolu.
- **A6.** `password === current_password`: `422`, `fields.password` dolu.
- **A7.** Politika ihlali (11 karakter / sembolsüz / `password_confirmation` uyumsuz):
  `422`, `fields.password` dolu.
- **A8.** Dakikada 6 istekten sonra `POST /api/password/change`: `429 TOO_MANY_ATTEMPTS`.
- **A9.** Kullanıcı A iki ayrı oturum açar; 1. oturumdan şifresini değiştirir → 2.
  oturumun bir sonraki isteği `401 UNAUTHENTICATED` (AuthenticateSession). Aynı
  senaryo: yönetici `POST /api/users/{A}/reset-password` çağırdığında A'nın açık
  oturumunun bir sonraki isteği `401` alır.
- **A10.** `must_change_password=false` kullanıcı için tüm mevcut endpoint davranışları
  değişmez (regresyon yok); `password/change` bu kullanıcı için de çalışır (gönüllü
  değişim, bayrak `false` kalır).
- **A11.** Pasif VE bayraklı kullanıcı: yanıt `403 USER_DEACTIVATED`'dır,
  `PASSWORD_CHANGE_REQUIRED` değil (middleware sırası: `active` önce).
- **A12.** `POST /api/login` yanıtındaki `data.must_change_password` doğru değeri taşır.

Frontend (manuel / E2E doğrulama):

- **F1.** Geçici şifreyle login → otomatik `/change-password`; sidebar/nav görünmez.
- **F2.** Bayraklıyken adres çubuğuna `/users` veya `/` yazmak `/change-password`'e döner.
- **F3.** Sayfa yenilendiğinde (`/api/me` üzerinden) ekran yine `/change-password`'dur.
- **F4.** Başarılı değişim: toast görünür, `state.from` (yoksa `/`) hedefine yönlenir,
  tekrar yönlendirme OLMAZ, `/api/me` yeniden çağrılmadan store günceldir.
- **F5.** "Çıkış yap" çalışır ve `/login`'e döner; ekranda başka çıkış yolu yoktur.
- **F6.** Yanlış mevcut şifre / zayıf şifre alan altında Türkçe hata gösterir; 429'da
  submit `Retry-After` süresince disable olur.
- **F7.** Bayraksız kullanıcı `/change-password`'e giderse `/`'a yönlenir.
- **F8.** API'den beklenmedik `PASSWORD_CHANGE_REQUIRED` gelirse (bayat client state)
  SPA `/change-password`'e gider; auth store temizlenmez.

---

## 7. Kapsam Dışı (bu turda YAPILMAYACAKLAR)

| Konu | Neden şimdi değil | Faz |
|---|---|---|
| Şifre geçmişi / son N şifrenin tekrar kullanım yasağı | `password_histories` tablosu gerekir; veri katmanı Faz 3'te açılıyor | Faz 3 sonrası, ayrı karar |
| Süre bazlı zorunlu şifre rotasyonu (90 gün vb.) | NIST 800-63B salt süreye dayalı rotasyonu önermiyor; istenirse `password_changed_at` kolonu + aynı middleware genişletilir | İhtiyaç halinde, Faz 10 (Settings) |
| Geçici şifrenin süre aşımı (X saat içinde ilk giriş yoksa geçersiz) | `users` tablosuna kolon + login dalı gerekir; ilk sürümde yönetici pasifleştirme yeterli telafi | Faz 10 |
| 2FA / TOTP | Ayrı ve büyük bir akış | Roadmap'te ayrıca planlanır |
| Gönüllü şifre değişimi UI'ı (profil/ayarlar ekranı) | Endpoint bu turda hazır; UI Faz 10 Settings'te aynı endpoint'i kullanır | Faz 10 |
| Yönetici onaylı "şifremi unuttum" kuyruğu (kalıcı tablo + UI) | Mevcut 202 + log davranışı korunuyor | Faz 10 |
| Tüm oturumları ANINDA sonlandırma (Redis sweep / user-id indeksli session) | Redis session'ları user ile indeksli değil; tembel model (`AuthenticateSession` + her istekte kontrol) projenin yerleşik ve yeterli modeli | Yapılmayacak (mimari karar) |
| `must_change_password` iken broadcast/kanal kısıtlaması | Reverb Faz 4'te geliyor; bayrağın `private-user.{id}` aboneliğini ENGELLEMEMESİ notu (revoke bildirimi alınabilmeli) Faz 4 sözleşmesine taşınır | Faz 4 |
