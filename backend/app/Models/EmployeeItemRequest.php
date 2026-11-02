<?php

namespace App\Models;

use App\EmployeeItemRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeItemRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'requested_by_employee_id',
        'request_number',
        'status',
        'purpose',
        'notes',
        'approved_by_user_id',
        'approved_at',
        'rejection_reason',
        'rejected_by_user_id',
        'rejected_at',
        'cancelled_by_user_id',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeItemRequestStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class,
            'requested_by_employee_id'
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeItemRequestItem::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}