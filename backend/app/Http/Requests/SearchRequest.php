<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/search?q=` — Faz 14 / İz F / C1.
 */
class SearchRequest extends FormRequest
{
    /**
     * Yetkilendirme TEK bir "bu ucu kullanabilir mi" sorusuna indirgenemez:
     * uç 7 farklı modülü birleştirir ve her modül kendi Policy'sinden ayrı
     * ayrı yetkilendirilir (bkz. `GlobalSearchService::search()`). Bu
     * yüzden burada `true` — modül bazlı filtre servis katmanındadır.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // MIN UZUNLUK KARARI: 2. Bu uç TEK bir istekte 7 tabloyu OR'lu
            // `LIKE '%…%'` ile tarar (önde joker olduğu için index kullanamaz
            // — repository `q` aramalarıyla AYNI, bilinen ve kabul edilmiş
            // maliyet). Tek karakterlik bir terim (ör. "a") neredeyse HER
            // satırla eşleşir: 7 sorgunun her biri en pahalı yolu (geniş tam
            // tarama + sıralama) yürütür ve komut paletine anlamsız denli
            // kalabalık bir sonuç kümesi döner (kullanıcı zaten `PER_MODULE_
            // LIMIT` yüzünden bunların hangi 5'ini gördüğünü seçemez). Bu uç
            // HER TUŞ VURUŞUNDA çağrılabileceği için (bkz. throttle notu,
            // routes/api.php) ilk karakterde bu maliyeti tetiklememek
            // (min:2) bilinçli bir tercih; 3 değil 2 seçildi çünkü iki
            // harfli gerçek kısaltmalar (ör. ticket önekleri, kişi baş
            // harfleri) hâlâ aranabilir kalsın istendi.
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function term(): string
    {
        return (string) $this->validated('q');
    }
}
