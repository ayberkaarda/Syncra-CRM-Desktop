<?php

namespace Tests\Feature\Concurrency;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Companies\CompanyService;
use App\Services\Contacts\ContactService;
use App\Services\Deals\DealService;
use App\Services\Leads\LeadService;
use App\Services\Products\ProductService;
use App\Services\Quotes\QuoteService;
use App\Services\Settings\PipelineStageService;
use App\Services\Settings\RoleMatrixService;
use App\Services\Sync\MutationApplier;
use App\Services\Tickets\TicketService;
use App\Services\Users\UserService;
use App\Sync\Mutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The writers LockOrderTest races against each other, as calls a worker
 * process can make by name. Every one is the production entry point, not a
 * copy of its statements.
 */
final class LockScenarios
{
    public static function deactivateUser(int $userId): bool
    {
        app(UserService::class)->toggleActive(User::query()->findOrFail($userId), false);

        return true;
    }

    public static function resetPassword(int $userId): bool
    {
        app(UserService::class)->resetPassword(User::query()->findOrFail($userId), 'Xy9!kLmn0pQrS#t');

        return true;
    }

    public static function deleteUser(int $userId): bool
    {
        app(UserService::class)->delete(User::query()->findOrFail($userId));

        return true;
    }

    public static function renameAndChangeRole(int $userId, string $role): bool
    {
        app(UserService::class)->update(User::query()->findOrFail($userId), [
            'name' => 'Renamed '.Str::random(4),
            'role' => $role,
        ]);

        return true;
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    public static function reorderStages(array $orderedIds): bool
    {
        app(PipelineStageService::class)->reorder($orderedIds);

        return true;
    }

    public static function deactivateStage(int $stageId): bool
    {
        app(PipelineStageService::class)->update(
            PipelineStage::query()->findOrFail($stageId),
            ['is_active' => false],
            Auth::user(),
        );

        return true;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public static function webCompanyTags(int $companyId, array $tagIds): bool
    {
        app(CompanyService::class)->update(Company::query()->findOrFail($companyId), ['tag_ids' => $tagIds]);

        return true;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public static function webLeadTags(int $leadId, array $tagIds): bool
    {
        app(LeadService::class)->update(Lead::query()->findOrFail($leadId), ['tag_ids' => $tagIds]);

        return true;
    }

    public static function webMakePrimaryContact(int $contactId): bool
    {
        app(ContactService::class)->update(Contact::query()->findOrFail($contactId), ['is_primary' => true]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $customFields
     */
    public static function webDealCustomFields(int $dealId, array $customFields): bool
    {
        app(DealService::class)->update(Deal::query()->findOrFail($dealId), ['custom_fields' => $customFields]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $customFields
     */
    public static function webTicketCustomFields(int $ticketId, array $customFields): bool
    {
        app(TicketService::class)->update(Ticket::query()->findOrFail($ticketId), ['custom_fields' => $customFields]);

        return true;
    }

    /**
     * A custom-field-only web edit through the owner's service.
     *
     * @param  class-string  $service
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $customFields
     */
    public static function webCustomFields(string $service, string $model, int $id, array $customFields): bool
    {
        app($service)->update($model::query()->findOrFail($id), ['custom_fields' => $customFields]);

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function webQuoteItems(int $quoteId, array $items): bool
    {
        app(QuoteService::class)->update(Quote::query()->findOrFail($quoteId), ['items' => $items]);

        return true;
    }

    /**
     * A web product edit that changes a column (drawing a version in its save)
     * and then the custom fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function webProductEdit(int $productId, array $data): bool
    {
        app(ProductService::class)->update(Product::query()->findOrFail($productId), $data);

        return true;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public static function syncRolePermissions(int $roleId, array $permissionNames): bool
    {
        /** @var User $actor */
        $actor = Auth::user();

        app(RoleMatrixService::class)->syncPermissions(Role::query()->findOrFail($roleId), $permissionNames, $actor);

        return true;
    }

    /**
     * A desktop `update` through the real push path (MutationApplier), based on
     * the row's current version.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function syncUpdate(string $entity, string $table, int $id, array $payload): string
    {
        $base = (int) DB::table($table)->where('id', $id)->value('sync_version');
        $map = [];

        $result = app(MutationApplier::class)->apply(Mutation::fromArray([
            'seq' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'op' => 'update',
            'entity' => $entity,
            'server_id' => $id,
            'base_sync_version' => $base,
            'changed_fields' => array_keys($payload),
            'payload' => $payload,
        ]), Auth::user(), $map);

        return $result->status.($result->code !== null ? '/'.$result->code : '');
    }
}
