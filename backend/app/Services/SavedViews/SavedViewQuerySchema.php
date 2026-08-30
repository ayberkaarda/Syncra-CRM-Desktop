<?php

namespace App\Services\SavedViews;

use App\Http\Requests\Leads\StoreLeadRequest;
use App\Services\Quotes\QuoteStatusMachine;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketStatusMachine;
use App\Support\MorphTargets;
use Illuminate\Validation\Rule;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler, modül başına BEYAZ LİSTE
 * (docs/PHASE-AUDIT.md §5.4: "izin verilen alan adları, operatörler ve sıralama sütunları
 * modül başına BEYAZ LİSTE olmalı ... beyaz listeyi onlardan [mevcut controller/Request'lerden]
 * türet, UYDURMA").
 *
 * TÜRETME KAYNAĞI (uydurma YOK, her satır aşağıdaki dosyalardan birebir okundu):
 *   - deals    -> app/Http/Requests/Deals/IndexDealRequest.php + DealRepository::SORTABLE_COLUMNS
 *   - leads    -> app/Http/Requests/Leads/IndexLeadRequest.php + LeadRepository::SORTABLE_COLUMNS
 *   - contacts -> app/Http/Requests/Contacts/IndexContactRequest.php + ContactRepository::SORTABLE_COLUMNS
 *   - companies-> app/Http/Requests/Companies/IndexCompanyRequest.php + CompanyRepository::SORTABLE_COLUMNS
 *   - quotes   -> app/Http/Requests/Quotes/IndexQuoteRequest.php + QuoteRepository::SORTABLE_COLUMNS
 *   - tickets  -> app/Http/Requests/Tickets/IndexTicketRequest.php + TicketRepository::SORTABLE_COLUMNS
 *   - tasks    -> app/Http/Requests/Tasks/IndexTaskRequest.php + TaskRepository::SORTABLE_COLUMNS
 *   - products -> app/Http/Requests/Products/IndexProductRequest.php + ProductRepository::SORTABLE_COLUMNS
 *   - users    -> app/Http/Requests/Users/IndexUserRequest.php + UserRepository::SORTABLE_COLUMNS
 *
 * `*Repository::SORTABLE_COLUMNS` `protected` olduğu ve bu servis o dosyaların SAHİBİ
 * olan paralel şeridin alanına GİRMEDEN (görev tanımı "SANA KAPALI" listesi) çalışması
 * gerektiği için sabitler burada KOPYALANDI, import EDİLMEDİ — repository'lerin kendisi
 * DEĞİŞTİRİLMEDİ (salt okunur referans). Bir sıralama sütunu repository tarafında
 * değişirse bu dosya da güncellenmeli; iki taraf da aynı "Faz 6 liste sözleşmesi"ni
 * belgeleyen yorumları taşıyor.
 *
 * Enum/whitelist DEĞERLERİ (durum, öncelik, kaynak...) ise KOPYALANMADI — ilgili sabit
 * PUBLIC olduğu her yerde (StoreLeadRequest::STATUSES/SOURCES, QuoteStatusMachine::statuses(),
 * TicketStatusMachine::statuses(), SlaService::PRIORITIES, MorphTargets::TARGETS) doğrudan
 * IMPORT edilip kullanıldı — tek doğruluk kaynağı bozulmadı.
 */
final class SavedViewQuerySchema
{
    /**
     * Modülün `filter.*` alanları -> Laravel doğrulama kuralları.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(string $module): array
    {
        return match ($module) {
            'deals' => [
                'stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
                'status' => ['nullable', Rule::in(['open', 'won', 'lost'])],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
                'amount_min' => ['nullable', 'numeric', 'min:0'],
                'amount_max' => ['nullable', 'numeric', 'min:0'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
            ],
            'leads' => [
                'status' => ['nullable', Rule::in(StoreLeadRequest::STATUSES)],
                'source' => ['nullable', Rule::in(StoreLeadRequest::SOURCES)],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'score_min' => ['nullable', 'integer', 'between:0,100'],
                'score_max' => ['nullable', 'integer', 'between:0,100'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            ],
            'contacts' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'is_primary' => ['nullable', 'boolean'],
                'city' => ['nullable', 'string', 'max:255'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
            ],
            'companies' => [
                'industry' => ['nullable', 'string', 'max:255'],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'city' => ['nullable', 'string', 'max:255'],
                'country' => ['nullable', 'string', 'max:255'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
            ],
            'quotes' => [
                'status' => ['nullable', Rule::in(QuoteStatusMachine::statuses())],
                'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'expired' => ['nullable', 'boolean'],
            ],
            'tickets' => [
                'status' => ['nullable', Rule::in(TicketStatusMachine::statuses())],
                'priority' => ['nullable', Rule::in(SlaService::PRIORITIES)],
                'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
                'category' => ['nullable', 'string', 'max:255'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
                'sla_breached' => ['nullable', 'boolean'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
            ],
            'tasks' => [
                'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
                'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
                'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
                'created_by' => ['nullable', 'integer', 'exists:users,id'],
                'taskable_type' => ['nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS))],
                'taskable_id' => ['nullable', 'integer'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'overdue' => ['nullable', 'boolean'],
            ],
            'products' => [
                'category' => ['nullable', 'string', 'max:255'],
                'is_active' => ['nullable', 'boolean'],
                'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
                'price_min' => ['nullable', 'numeric', 'min:0'],
                'price_max' => ['nullable', 'numeric', 'min:0'],
                'in_stock' => ['nullable', 'boolean'],
            ],
            'users' => [
                'role' => ['nullable', 'string', 'exists:roles,name'],
                'is_active' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * Modülün sıralanabilir sütunları (`sort` — `-` öneki yön içindir, ayrıca ele alınır).
     *
     * @return list<string>
     */
    public static function sortColumns(string $module): array
    {
        return match ($module) {
            'deals' => ['title', 'amount', 'expected_close_date', 'closed_at', 'status', 'created_at'],
            'leads' => ['first_name', 'last_name', 'email', 'company_name', 'source', 'status', 'score', 'created_at'],
            'contacts' => ['first_name', 'last_name', 'email', 'position', 'city', 'created_at'],
            'companies' => ['name', 'industry', 'city', 'country', 'employee_count', 'annual_revenue', 'created_at'],
            'quotes' => ['quote_number', 'title', 'status', 'total', 'valid_until', 'created_at'],
            'tickets' => ['ticket_number', 'subject', 'priority', 'status', 'sla_due_at', 'created_at', 'resolved_at'],
            'tasks' => ['title', 'due_at', 'priority', 'status', 'completed_at', 'created_at'],
            'products' => ['name', 'sku', 'category', 'unit_price', 'stock_quantity', 'created_at'],
            'users' => ['name', 'email', 'department', 'is_active', 'last_login_at', 'created_at'],
            default => [],
        };
    }
}
