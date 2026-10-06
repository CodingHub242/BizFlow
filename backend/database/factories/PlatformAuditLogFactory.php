<?php

namespace Database\Factories;

use App\Models\PlatformAuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAuditLogFactory extends Factory
{
    protected $model = PlatformAuditLog::class;

    public function definition(): array
    {
        return [
            'platform_admin_id' => PlatformAdmin::factory(),
            'action' => 'business.approved',
            'target_type' => Tenant::class,
            'target_id' => Tenant::factory(),
            'reason' => null,
            'metadata' => null,
        ];
    }
}