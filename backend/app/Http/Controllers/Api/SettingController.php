<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\SettingResource;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `GET|PATCH /api/settings` — sistem ayarları.
 *
 * -----------------------------------------------------------------------------
 * İZİN: `settings.manage` (OKUMA DAHİL)
 * -----------------------------------------------------------------------------
 * Ayrı bir SettingPolicy YOK: `settings.manage` tek bir yetenektir ("ayarları
 * yönet") ve bir Setting ÖRNEĞİ üzerinde verilen bir karar değildir —
 * RoleController'daki ile aynı desen, doğrudan izin adı sorulur.
 *
 * Okuma da aynı izne bağlıdır. `is_public` kolonu buna rağmen anlamlıdır: bir
 * ayarın kimlik doğrulaması olmadan (ör. giriş ekranındaki şirket adı/logosu)
 * sızdırılabilir olup olmadığını işaretler. Öyle bir uç bu fazda YOKTUR ve
 * `is_public` bu uçtan da değiştirilemez (bkz. SettingsService) — bayrak,
 * onu okuyacak uç yazıldığında hazır olsun diye taşınır. Kapalı devre bir
 * CRM'de "vergi numarası" ile "varsayılan para birimi" arasında yetki farkı
 * yaratmak, ayar başına izin tanımlamak demek olurdu; bunun karşılığı yok.
 */
class SettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(): JsonResponse
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);

        return $this->respond();
    }

    /**
     * `PATCH /api/settings` — gövde düz bir `{ anahtar: değer }` haritası.
     *
     * Kısmi güncelleme yapılır (gönderilmeyen ayar değişmez) ama gönderilenler
     * ya HEP birlikte yazılır ya da hiçbiri (SettingsService::update tek
     * transaction).
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);

        $this->settings->update($request->settings());

        return $this->respond();
    }

    /**
     * Her iki uç da TÜM ayarları döner: kaydettikten sonra istemcinin
     * ekranı tazelemek için ikinci bir GET atmasına gerek kalmaz ve
     * sunucunun cast ettiği değerler (ör. `"20"` -> `20`) doğrudan forma
     * oturur.
     */
    protected function respond(): JsonResponse
    {
        return response()->json([
            'data' => SettingResource::collection($this->settings->all()),
            'meta' => [
                'groups' => $this->settings->groups(),
            ],
        ]);
    }
}
