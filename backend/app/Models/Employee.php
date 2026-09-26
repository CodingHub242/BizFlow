<?php

namespace App\Models;

use App\EmploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'branch_id',
        'employee_number',
        'phone',
        'job_title',
        'employment_status',
        'hired_at',
    ];

    protected $casts = [
        'employment_status' => EmploymentStatus::class,
        'hired_at' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}