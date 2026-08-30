<?php

namespace App\Services\Companies;

use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Repositories\CompanyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyService
{
    public function __construct(protected CompanyRepository $companies) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->companies->paginate($filters, $perPage);
    }

    public function find(int $id): Company
    {
        return $this->companies->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            $tagIds = $data['tag_ids'] ?? null;
            $customFields = $data['custom_fields'] ?? null;
            unset($data['tag_ids'], $data['custom_fields']);

            $company = $this->companies->create($data);

            if ($tagIds !== null) {
                $this->companies->syncTags($company, $tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($company, $customFields);
            }

            return $this->companies->findOrFail($company->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function update(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data) {
            $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
            $customFields = array_key_exists('custom_fields', $data) ? $data['custom_fields'] : null;
            unset($data['tag_ids'], $data['custom_fields']);

            if (! empty($data)) {
                $this->companies->update($company, $data);
            }

            if ($tagIds !== null) {
                $this->companies->syncTags($company, $tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($company, $customFields);
            }

            return $this->companies->findOrFail($company->id);
        });
    }

    public function delete(Company $company): void
    {
        // Silme koruması: açık fırsatı olan bir firma sessizce silinmesin.
        if ($this->companies->hasOpenDeals($company)) {
            throw ValidationException::withMessages([
                'deals' => 'Bu firmanın açık fırsatları var, silinemez.',
            ]);
        }

        $this->companies->delete($company);
    }

    /**
     * Gönderilen değerleri, `companies` entity_type'ına tanımlı özel alanlarla
     * eşleştirip kaydeder. Tanımlı olmayan bir anahtar sessizce yok sayılır.
     *
     * @param  array<string, mixed>  $values
     */
    protected function syncCustomFields(Company $company, array $values): void
    {
        $definitions = $this->companies->customFieldDefinitions();

        foreach ($values as $key => $value) {
            $field = $definitions->get($key);

            if (! $field) {
                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_id' => $field->id,
                    'customizable_type' => Company::class,
                    'customizable_id' => $company->id,
                ],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                ]
            );
        }
    }
}
