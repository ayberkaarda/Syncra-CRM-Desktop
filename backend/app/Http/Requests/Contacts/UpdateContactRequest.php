<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOT (Faz 13 / F8): `owner_id` bu uçta BİLEREK yazılabilir kaldı.
 *
 * Deal/Lead/Task/Ticket'ta sahip alanı `missing` yapıldı, çünkü oralarda
 * devretme ayrı bir izinle (`*.assign`) korunan ayrı bir uçtur ve genel
 * update ucu o kapıyı baypas ediyordu. Contact tarafında böyle bir kapı
 * YOK: izin sözlüğünde `contacts.assign` diye bir satır ve `/assign` diye bir
 * uç bulunmuyor (bkz. RolePermissionSeeder). Alanı burada kapatmak, baypas
 * edilecek bir korumayı korumak yerine sahibi belirlemenin TEK yolunu
 * kaldırırdı. Contact paylaşılan master data'dır; gerekçenin tamamı
 * ContactPolicy::update() dokümanındadır.
 */
class UpdateContactRequest extends FormRequest
{
    /**
     * Yetkilendirme ContactController::update() içinde Policy ile yapılır.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Formun tamamını geri gönderen istemci mevcut sahibi DEĞİŞTİRMEDEN
     * yollar. Sahip sonradan devre dışı kaldıysa bu değer aşağıdaki "aktif
     * kullanıcı" kuralına takılır ve kişi hiç düzenlenemez hâle gelirdi.
     * Değişmeyen değer zaten bir no-op olduğu için girdiden düşürülür; yeni
     * bir sahip seçmek ise kuralın tamamından geçer.
     */
    protected function prepareForValidation(): void
    {
        $contact = $this->route('contact');
        $ownerId = $this->input('owner_id');

        if ($contact instanceof Contact && is_scalar($ownerId) && $contact->owner_id !== null
            && (string) $ownerId === (string) $contact->owner_id) {
            $this->getInputSource()->remove('owner_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
            'is_primary' => ['sometimes', 'boolean'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
