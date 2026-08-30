<?php

namespace App\Http\Requests\Logs;

use App\Models\PageVisitLog;
use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    /**
     * IDOR koruması burada yapılır: route model binding {pageVisit}'i zaten
     * çözmüş olur (SubstituteBindings, FormRequest resolve edilmeden önce
     * çalışır), biz sadece kaydın istek sahibine ait olduğunu doğruluyoruz.
     * false dönmesi Laravel'de AuthorizationException -> AccessDeniedHttpException
     * -> merkezi hata zarfında 403 FORBIDDEN olarak sonuçlanır (bkz. bootstrap/app.php).
     *
     * Gövdede istemciden herhangi bir süre alanı KABUL EDİLMİYOR: rules() boş,
     * çünkü duration_seconds tamamen sunucu tarafında (PageVisitService) hesaplanır.
     */
    public function authorize(): bool
    {
        $pageVisit = $this->route('pageVisit');

        return $pageVisit instanceof PageVisitLog
            && $this->user() !== null
            && $pageVisit->user_id === $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
