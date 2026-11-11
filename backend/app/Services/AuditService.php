<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    public function record(
        string $action,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null
    ): AuditLog {
        $request ??= request();

        $user = Auth::user();

        $requestId = $request->header('X-Request-ID');

        if (!$requestId || strlen($requestId) > 100) {
            $requestId = (string) Str::uuid();
        }

        return AuditLog::create([
            'tenant_id' => $model->getAttribute('tenant_id')
                ?? $user?->tenant_id,

            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,

            'action' => $action,

            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),

            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,

            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),

            'url' => $request->url(),
            'http_method' => $request->method(),

            'request_id' => $requestId,

            'created_at' => now(),
        ]);
    }
}
