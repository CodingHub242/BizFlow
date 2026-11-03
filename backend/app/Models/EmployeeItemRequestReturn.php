<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeItemRequestReturn extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_item_request_id',
        'return_number',
        'received_by_user_id',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    public function employeeItemRequest(): BelongsTo
    {
        return $this->belongsTo(EmployeeItemRequest::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeItemRequestReturnItem::class);
    }
}