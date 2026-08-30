<?php

namespace App\Services\SavedViews;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler (docs/PHASE-INTL.md §3).
 *
 * Kısa modül adı -> Model FQCN beyaz listesi. `App\Services\Search\GlobalSearchService`
 * İLE AYNI DESEN (bkz. o sınıfın "Aranacak modüllerin KISA AD -> Model FQCN beyaz listesi"
 * yorumu) ama AYRI bir sabit: arama 7 modülü kapsar (Quote/User dahil, Task/Product HARİÇ),
 * kayıtlı görünümler görev tanımındaki 9 liste ekranını kapsar (Task/Product dahil). İki
 * whitelist farklı yüzeyler için var, birleştirilmedi.
 *
 * Bu eşleme, her modülün Policy'sindeki `viewAny(User $user): bool` metoduna (dolayısıyla
 * `user->can('<modül>.view')` iznine) `Gate::allows('viewAny', ModelClass)` ile yönlenmek
 * için kullanılır — `SavedViewPolicy` kendi izin mantığını İCAT ETMEZ, mevcut Policy
 * katmanına devreder (yine GlobalSearchService ile aynı karar).
 */
final class SavedViewModules
{
    /**
     * @var array<string, class-string>
     */
    public const MODULES = [
        'deals' => Deal::class,
        'leads' => Lead::class,
        'contacts' => Contact::class,
        'companies' => Company::class,
        'quotes' => Quote::class,
        'tickets' => Ticket::class,
        'tasks' => Task::class,
        'products' => Product::class,
        'users' => User::class,
    ];

    public static function isValid(string $module): bool
    {
        return array_key_exists($module, self::MODULES);
    }

    /**
     * @return class-string|null
     */
    public static function modelClass(string $module): ?string
    {
        return self::MODULES[$module] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::MODULES);
    }
}
