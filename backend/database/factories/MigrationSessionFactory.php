<?php

namespace Database\Factories;

use App\MigrationSessionStatus;
use App\MigrationSource;
use App\Models\MigrationSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MigrationSession>
 */
class MigrationSessionFactory extends Factory
{
    protected $model = MigrationSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'source' => MigrationSource::QUICKBOOKS,
            'status' => MigrationSessionStatus::PENDING,
        ];
    }
}