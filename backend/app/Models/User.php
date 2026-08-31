<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /**
     * `HasApiTokens` (Faz F1 — SYNCDESKTOP K4 / §4.3).
     *
     * The desktop client cannot use the SPA cookie flow: it has no browser
     * origin, no CSRF cookie and needs a credential that survives restarts.
     * It authenticates once through POST /api/auth/device and carries a
     * Sanctum personal access token whose only ability is `desktop`.
     *
     * The web flow is UNCHANGED. Sanctum still authenticates the SPA from the
     * session (config/sanctum.php `guard => ['web']` is tried first), and
     * `/api/me` keeps its exact shape because UserResource is an explicit
     * whitelist rather than `toArray()`. The trait adds only tokens(),
     * tokenCan(), tokenCant(), createToken(), generateTokenString(),
     * currentAccessToken() and withAccessToken() - checked against Notifiable,
     * HasRoles/HasPermissions, LogsCrmActivity and SoftDeletes, nothing
     * collides, and `tokens()` is a relation that only serialises when eager
     * loaded (it never is).
     *
     * ONE CONSEQUENCE IS NOT OBVIOUS: from this line on, every cookie session
     * also satisfies `ability:desktop`. Sanctum's Guard hands a session user a
     * TransientToken whose can() returns an unconditional true, so
     * CheckForAnyAbility passes for the SPA as well. That is why the sync
     * routes carry `device.token` (App\Http\Middleware\EnsureDeviceToken) on
     * top of `ability:desktop` - see that class and protocol §3.3/K-A.
     */
    use HasApiTokens, HasFactory, HasRoles, LogsCrmActivity, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department',
        // Kişisel arayüz tercihleri (Faz 14). Mass-assignment'a AÇIK ama serbest DEĞİL:
        // her iki alan da `config('syncra.i18n.supported_locales')` / `syncra.currency.supported`
        // beyaz listesine karşı doğrulanır (UpdatePreferencesRequest). Fillable olmaları,
        // tercihi yazan servisin `forceFill` gibi kaçamaklara sapmasını engeller.
        'locale',
        'preferred_currency',
        'is_active',
        'must_change_password',
    ];

    /**
     * MODEL VARSAYILANLARI — GÖÇTEKİ DB VARSAYILANLARIYLA BİREBİR AYNI OLMAK ZORUNDA
     * (`2026_08_25_700001_add_locale_and_preferred_currency_to_users_table`).
     *
     * NEDEN GEREKLİ (Faz 14, ölçülmüş hata): iki kolon `fillable` ve DB tarafında DEFAULT'lu.
     * Bu blok olmadan `User::create()` onları INSERT'e hiç katmıyor, satır DB varsayılanını
     * alıyor ama BELLEKTEKİ model örneği o nitelikleri HİÇ taşımıyordu. `LogsCrmActivity`
     * `logFillable()` kullandığı için spatie, "fillable ama modelde yok" olan alanları her
     * kayıtta DEĞİŞMİŞ sayıyor ve `logOnlyDirty()` filtresini boşa çıkarıyordu:
     * `remember_token` tazelenmesi gibi HİÇBİR ŞEYİN değişmediği bir kaydın bile
     * `["locale","preferred_currency"]` içeren bir audit satırı yazmasına yol açıyordu.
     * Faz 5'te bilinçle gürültüden arındırılan denetim izini bu, üretimde de kirletirdi.
     *
     * ÇÖZÜM YÖNÜ OLARAK `logExcept` REDDEDİLDİ: dil/para birimi tercihi DEĞİŞTİĞİNDE
     * denetlenmeye değer meşru bir olaydır. Sorun alanların loglanması değil, DEĞİŞMEDİKLERİ
     * HÂLDE loglanmalarıydı — düzeltme de tam oraya, modelin bilgi eksiğine yapılır.
     *
     * Değerler config'ten OKUNAMAZ (property initializer sabit olmak zorunda); göçle aynı
     * literal tutulur ve ikisi birlikte değişir.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'tr',
        'preferred_currency' => 'TRY',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
