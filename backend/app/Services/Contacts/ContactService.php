<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\CustomFieldValue;
use App\Repositories\ContactRepository;
use App\Sync\SyncVersionBumper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactService
{
    public function __construct(protected ContactRepository $contacts) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->contacts->paginate($filters, $perPage);
    }

    public function find(int $id): Contact
    {
        return $this->contacts->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data): Contact
    {
        return DB::transaction(function () use ($data) {
            $tagIds = $data['tag_ids'] ?? null;
            $customFields = $data['custom_fields'] ?? null;
            unset($data['tag_ids'], $data['custom_fields']);

            $contact = $this->contacts->create($data);

            // İş kuralı: bir firmada yalnızca bir is_primary=true kişi olabilir.
            if (($data['is_primary'] ?? false) === true && $contact->company_id) {
                $this->contacts->clearOtherPrimaryContacts($contact->company_id, $contact->id);
            }

            if ($tagIds !== null) {
                $this->contacts->syncTags($contact, $tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($contact, $customFields);
            }

            return $this->contacts->findOrFail($contact->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data) {
            $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
            $customFields = array_key_exists('custom_fields', $data) ? $data['custom_fields'] : null;
            unset($data['tag_ids'], $data['custom_fields']);

            if (! empty($data)) {
                $this->contacts->update($contact, $data);
            }

            // İş kuralı: bir firmada yalnızca bir is_primary=true kişi olabilir.
            // Güncel company_id'yi (bu istekte değişmiş olabilir) kullan.
            if (($data['is_primary'] ?? false) === true && $contact->company_id) {
                $this->contacts->clearOtherPrimaryContacts($contact->company_id, $contact->id);
            }

            if ($tagIds !== null) {
                $this->contacts->syncTags($contact, $tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($contact, $customFields);
            }

            return $this->contacts->findOrFail($contact->id);
        });
    }

    public function delete(Contact $contact): void
    {
        // Silme koruması: açık fırsatı olan bir kişi sessizce silinmesin.
        if ($this->contacts->hasOpenDeals($contact)) {
            throw ValidationException::withMessages([
                'deals' => 'Bu kişinin açık fırsatları var, silinemez.',
            ]);
        }

        $this->contacts->delete($contact);
    }

    /**
     * Gönderilen değerleri, `contacts` entity_type'ına tanımlı özel alanlarla
     * eşleştirip kaydeder. Tanımlı olmayan bir anahtar sessizce yok sayılır
     * (bilinmeyen bir custom field key'i validasyon hatası üretmez, ama veri
     * bütünlüğü için de yazılmaz).
     *
     * @param  array<string, mixed>  $values
     */
    protected function syncCustomFields(Contact $contact, array $values): void
    {
        $definitions = $this->contacts->customFieldDefinitions();

        $changed = false;

        foreach ($values as $key => $value) {
            $field = $definitions->get($key);

            if (! $field) {
                continue;
            }

            $row = CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_id' => $field->id,
                    'customizable_type' => Contact::class,
                    'customizable_id' => $contact->id,
                ],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                ]
            );

            $changed = $changed || $row->wasRecentlyCreated || $row->wasChanged();
        }

        // Embedded child (protocol §1.5): `custom_field_values` is not a pull
        // table - its rows ride inside the owner's `custom_fields` payload.
        // When ONLY a custom field changed, the owner row itself is clean, no
        // observer fires, and the edit would never cross a client's cursor.
        // Bumped only when something actually changed, so a no-op upsert does
        // not manufacture a phantom delta.
        if ($changed) {
            SyncVersionBumper::bump($contact);
        }
    }
}
