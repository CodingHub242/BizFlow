<?php

namespace App\Services;

use App\Models\Category;
use RuntimeException;

class MigrationCategoryResolver
{
    public function resolve(int $tenantId, string $name): Category
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(
                'Category name is required to resolve an expense category.'
            );
        }

        $category = Category::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (!$category) {
            throw new RuntimeException(
                "Category '{$name}' could not be found for this tenant."
            );
        }

        return $category;
    }
}