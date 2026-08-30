<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     * Kullanıcı girdisi doğrudan orderBy'a verilmez (SQL injection/hata riski).
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'name',
        'email',
        'department',
        'is_active',
        'last_login_at',
        'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış kullanıcı listesini sayfalı döner.
     *
     * @param  array{q?: ?string, role?: ?string, is_active?: mixed, sort?: ?string}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['role'])) {
            $role = $filters['role'];

            $query->whereHas('roles', function (Builder $query) use ($role) {
                $query->where('name', $role);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);

        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    /**
     * Sıralama parametresini beyaz liste üzerinden çözer.
     * Listede olmayan bir sütun gelirse varsayılana (-created_at) düşer.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(?string $sort): array
    {
        if (empty($sort)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        $direction = 'asc';
        $column = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $column = substr($sort, 1);
        }

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        return [$column, $direction];
    }

    public function findOrFail(int $id): User
    {
        return User::with('roles')->findOrFail($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();

        return $user;
    }

    public function delete(User $user): void
    {
        $user->delete();
    }
}
