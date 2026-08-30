<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\MoveDealRequest;
use App\Http\Resources\DealCardResource;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DealMoveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /api/deals/{deal}/move` — Kanban kartını taşı.
 *
 * Kart taşıma, Deal CRUD'undan AYRI bir controller'dadır: kendine ait bir
 * eşzamanlılık sözleşmesi (satır kilidi + `version`), kendine ait bir hata
 * kodu (409 DEAL_VERSION_CONFLICT) ve kendine ait bir yayını (`deal.moved`)
 * vardır. Bunları `DealController::update()` içine katlamak, sıradan bir alan
 * düzenlemesini de aynı ağır yola sokardı.
 *
 * İnce controller: yetki + Form Request + DealMoveService devri.
 */
class DealMoveController extends Controller
{
    public function __construct(private readonly DealMoveService $moves) {}

    public function update(MoveDealRequest $request, Deal $deal): JsonResponse
    {
        // Yetki kararı DealPolicy::move()'da, projenin geri kalanıyla aynı
        // desende. `$user->can('deals.move')` ile burada kontrol etmek bugün
        // aynı sonucu verirdi, ama "kapanmış kart taşınamaz" ya da "yalnızca
        // sahibi taşıyabilir" gibi bir kural eklendiğinde Policy'ye yazılan o
        // kural bu yolu ATLARDI. Yetki tek yerde toplanır.
        Gate::authorize('move', $deal);

        /** @var User $actor */
        $actor = $request->user();

        $deal = $this->moves->move($deal, $request->movePayload(), $actor);

        // Panonun kart şekliyle BİREBİR aynı gösterim. Akış şu: pano
        // `GET /api/deals/board` ile yüklenir (DealCardResource) -> kullanıcı
        // kartı sürükler -> sunucu kartı döner -> istemci AYNI nesneyi yerine
        // oturtur. Ayrı bir şekil döndürmek, taşınan kartı komşularından farklı
        // alanlara sahip bırakır ve `is_overdue` gibi hesaplanan alanlar
        // taşımadan sonra sessizce kaybolur.
        //
        // `loadMissing`: DealCardResource ilişkileri yalnızca YÜKLENMİŞSE
        // yazar, aksi hâlde null/boş döner — yani eager load yapılmazsa yanıt
        // sessizce EKSİK çıkar. `load` değil `loadMissing`, çünkü `owner`
        // yayın gövdesi için serviste zaten çözülmüş olabilir; `load` onu
        // ikinci kez sorgulardı.
        $deal->loadMissing(['company', 'contact', 'owner', 'tags']);

        // 409 yanıtı da AYNI kaynağı taşır: istemci başarı ve çakışma
        // yollarında tek bir "kartı yerine oturt" fonksiyonu kullanır.
        return (new DealCardResource($deal))->response();
    }
}
