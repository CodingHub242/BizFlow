<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_feature_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('feature_key');
            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'feature_key'],
                'tenant_feature_settings_unique'
            );
        });

        // Enable BeezBud for all existing tenants.
        DB::table('tenants')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($tenants) {
                $now = now();

                $settings = $tenants->map(fn ($tenant) => [
                    'tenant_id' => $tenant->id,
                    'feature_key' => 'beezbud',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if (!empty($settings)) {
                    DB::table('tenant_feature_settings')->insert($settings);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_settings');
    }
};
