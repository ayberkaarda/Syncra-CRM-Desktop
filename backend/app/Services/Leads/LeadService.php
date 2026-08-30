<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Repositories\LeadRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(protected LeadRepository $leads) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->leads->paginate($filters, $perPage);
    }

    public function find(int $id): Lead
    {
        return $this->leads->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data) {
            $tagIds = $data['tag_ids'] ?? [];
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            $data['status'] = $data['status'] ?? 'new';

            $lead = $this->leads->create($data);

            if (! empty($tagIds)) {
                $this->leads->syncTags($lead, $tagIds);
            }

            if (! empty($customFields)) {
                $this->leads->syncCustomFieldValues($lead, $customFields);
            }

            $lead->load(['owner', 'tags', 'customFieldValues.customField']);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function update(Lead $lead, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $data) {
            $hasTagIds = array_key_exists('tag_ids', $data);
            $tagIds = $data['tag_ids'] ?? [];
            $hasCustomFields = array_key_exists('custom_fields', $data);
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            if (! empty($data)) {
                $this->leads->update($lead, $data);
            }

            if ($hasTagIds) {
                $this->leads->syncTags($lead, $tagIds ?? []);
            }

            if ($hasCustomFields && ! empty($customFields)) {
                $this->leads->syncCustomFieldValues($lead, $customFields);
            }

            $lead->load(['owner', 'tags', 'customFieldValues.customField']);

            return $lead;
        });
    }

    public function delete(Lead $lead): void
    {
        $this->leads->delete($lead);
    }

    public function assign(Lead $lead, int $ownerId): Lead
    {
        $this->leads->update($lead, ['owner_id' => $ownerId]);

        $lead->load(['owner', 'tags', 'customFieldValues.customField']);

        return $lead;
    }

    /**
     * `POST /api/leads` yanıtına bilgi amaçlı eklenen `meta.duplicate_warning`
     * için A şeridinin `DuplicateDetector`'ını (mevcutsa) çağırır. Servis
     * henüz oluşmadıysa sessizce boş koleksiyon döner — store 201 yanıtını
     * bloklamaz.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<int, mixed>
     */
    public function findDuplicateWarnings(array $input): Collection
    {
        if (! class_exists(DuplicateDetector::class)) {
            return collect();
        }

        return app(DuplicateDetector::class)->findCandidates($input);
    }

    /**
     * A şeridinin `LeadConversionService`'ine ince bir vekil. Servis henüz
     * oluşmadıysa 503 anlamında bir istisna fırlatır (controller yakalar).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function convert(Lead $lead, array $options, User $actor): array
    {
        if (! class_exists(LeadConversionService::class)) {
            throw new \RuntimeException('LeadConversionService henüz hazır değil.');
        }

        return app(LeadConversionService::class)->convert($lead, $options, $actor);
    }
}
