<?php

namespace App\Http\Requests\Companies;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOT (Faz 13 / F8): `owner_id` bu uçta BİLEREK yazılabilir kaldı.
 *
 * Deal/Lead/Task/Ticket'ta sahip alanı `missing` yapıldı, çünkü oralarda
 * devretme ayrı bir izinle (`*.assign`) korunan ayrı bir uçtur ve genel
 * update ucu o kapıyı baypas ediyordu. Company tarafında böyle bir kapı
 * YOK: izin sözlüğünde `companies.assign` diye bir satır ve `/assign` diye bir
 * uç bulunmuyor (bkz. RolePermissionSeeder). Alanı burada kapatmak, baypas
 * edilecek bir korumayı korumak yerine sahibi belirlemenin TEK yolunu
 * kaldırırdı. Company paylaşılan master data'dır; gerekçenin tamamı
 * CompanyPolicy::update() dokümanındadır.
 */
class UpdateCompanyRequest extends FormRequest
{
    /**
     * Yetkilendirme CompanyController::update() içinde Policy ile yapılır.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Formun tamamını geri gönderen istemci mevcut sahibi DEĞİŞTİRMEDEN
     * yollar. Sahip sonradan devre dışı kaldıysa bu değer aşağıdaki "aktif
     * kullanıcı" kuralına takılır ve firma hiç düzenlenemez hâle gelirdi.
     * Değişmeyen değer zaten bir no-op olduğu için girdiden düşürülür; yeni
     * bir sahip seçmek ise kuralın tamamından geçer.
     */
    protected function prepareForValidation(): void
    {
        $company = $this->route('company');
        $ownerId = $this->input('owner_id');

        if ($company instanceof Company && is_scalar($ownerId) && $company->owner_id !== null
            && (string) $ownerId === (string) $company->owner_id) {
            $this->getInputSource()->remove('owner_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Yalnızca http/https: düz `url` kuralı `javascript:`, `file:`
            // gibi şemaları da geçerli sayar ve bu değer arayüzde bağlantı
            // olarak açılır.
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employee_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'annual_revenue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
