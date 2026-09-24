<?php

namespace App\Services;

use App\CatalogItemType;
use App\Models\CatalogItem;

class MigrationCatalogItemCreator
{
    public function create(int $tenantId, array $data): CatalogItem
    {
        $sku = isset($data['sku'])
            ? trim((string) $data['sku'])
            : null;

        if ($sku !== null && $sku !== '') {
            $existing = CatalogItem::query()
                ->where('tenant_id', $tenantId)
                ->where('sku', $sku)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $type = $data['type'] ?? CatalogItemType::PRODUCT;

        if (is_string($type)) {
            $type = CatalogItemType::from($type);
        }

        return CatalogItem::create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'name' => $data['name'],
            'sku' => $sku ?: null,
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'cost_price' => $data['cost_price'] ?? 0,
            'selling_price' => $data['selling_price'] ?? 0,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'track_inventory' => $data['track_inventory'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}