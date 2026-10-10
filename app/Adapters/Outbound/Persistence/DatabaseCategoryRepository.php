<?php

namespace App\Adapters\Outbound\Persistence;

use Application\Ports\CategoryRepository;
use Illuminate\Support\Facades\DB;

final class DatabaseCategoryRepository implements CategoryRepository
{
    public function exists(string $id): bool
    {
        return DB::table('library.category')->where('id', $id)->exists();
    }

    public function findAllNames(): array
    {
        return DB::table('library.category')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function findNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('library.category')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }
}
