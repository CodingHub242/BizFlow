<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeItemRequestIssue extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_item_request_id',
        'branch_id',
        'issue_number',
        'issued_by_user_id',
        'issued_at',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function employeeItemRequest(): BelongsTo
    {
        return $this->belongsTo(EmployeeItemRequest::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeItemRequestIssueItem::class);
    }
}