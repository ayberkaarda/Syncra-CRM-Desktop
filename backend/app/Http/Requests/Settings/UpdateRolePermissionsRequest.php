<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/settings/roles/{role}/permissions` — gövde:
 * `{ "permissions": ["leads.view", "leads.create"] }`.
 *
 * Liste TAM DURUMDUR (sync), fark değil: gönderilmeyen her izin kaldırılır.
 * "Ekle/çıkar" uçları yerine bunun seçilmesinin nedeni matris ekranının
 * kendisidir — kullanıcı bir satırda birden çok kutuyu işaretleyip tek kez
 * kaydeder; fark hesabını istemciye bırakmak, iki eşzamanlı düzenlemede
 * hangi kutunun kazandığını belirsiz kılardı.
 *
 * Boş dizi GEÇERLİDİR: bir rolün tüm izinlerini kaldırmak meşru bir
 * işlemdir (rol devre dışı bırakılmadan boşaltılabilir). Bu yüzden kural
 * `required` değil `present` + `array`.
 *
 * İzin ADLARININ var olup olmadığı burada `exists` ile DEĞİL, serviste
 * kontrol edilir: `permissions` tablosunda `guard_name` de vardır ve
 * yalnızca `name` üzerinden `exists` kontrolü, başka bir guard'a ait bir
 * satırı geçerli sayardı.
 */
class UpdateRolePermissionsRequest extends FormRequest
{
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
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.present' => __('validation.custom.settings.role_permissions_present'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return array_values(array_map('strval', (array) $this->validated()['permissions']));
    }
}
