<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RuntimePlanLimit extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_key',
        'resource_key',
        'limit_value',
        'period',
        'is_hard_limit',
        'is_active',
        'metadata',
        'starts_at',
        'ends_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'is_hard_limit' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
