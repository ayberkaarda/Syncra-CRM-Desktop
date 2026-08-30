<?php

namespace App\Http\Requests\Leads;

use App\Http\Requests\Concerns\ForcesRecordOwnerOnCreate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportLeadsRequest extends FormRequest
{
    use ForcesRecordOwnerOnCreate;

    /**
     * `owner_id` yalnızca `leads.assign` iznine sahip aktörden kabul edilir;
     * aksi hâlde isteği yapan kullanıcıya sabitlenir (gerekçe:
     * ForcesRecordOwnerOnCreate).
     */
    protected function prepareForValidation(): void
    {
        $this->forceOwnerUnlessAssigner('owner_id', 'leads.assign');
    }

    /**
     * Yetkilendirme LeadImportController::store() içinde Policy ile yapılır.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * İzin verilen duplicate stratejileri. Sözleşme: LeadImportService bu
     * üç değeri bilir, dördüncüsü yok.
     *
     * @var array<int, string>
     */
    public const DUPLICATE_MODES = ['skip', 'create', 'update'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `mimes` uzantı/tür beyaz listesi (Laravel bunu dosyanın
            // gerçek MIME'ından türetilen uzantıyla karşılaştırır, ham
            // Content-Type başlığına güvenmez). `mimetypes` bunun üstüne
            // gerçek algılanan MIME string'ini de doğrular — yalnızca
            // uzantı kontrolü, adı ".csv" olan herhangi bir dosyanın
            // (ör. içine PHP kodu gömülü bir dosyanın) geçmesine izin
            // verirdi. `text/plain` listede: gerçek dünyada birçok CSV
            // dosyası (özellikle Notepad ile kaydedilenler) finfo
            // tarafından "text/plain" olarak algılanır, salt "text/csv"
            // beklemek bu dosyaları haksız yere reddederdi.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv'],
            'duplicate_mode' => ['nullable', 'string', Rule::in(self::DUPLICATE_MODES)],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function duplicateMode(): string
    {
        return $this->validated('duplicate_mode') ?? 'skip';
    }

    public function ownerId(): ?int
    {
        $value = $this->validated('owner_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => __('validation.custom.leads.import_invalid_type'),
            'file.mimetypes' => __('validation.custom.leads.import_invalid_content'),
            'file.max' => __('validation.custom.leads.import_too_large', ['max_mb' => 5]),
            'duplicate_mode.in' => __('validation.custom.leads.import_duplicate_mode'),
        ];
    }
}
