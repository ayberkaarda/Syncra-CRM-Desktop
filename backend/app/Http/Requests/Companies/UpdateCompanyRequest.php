<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employee_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'annual_revenue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
