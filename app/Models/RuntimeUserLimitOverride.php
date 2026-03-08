<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RuntimeUserLimitOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_key',
        'limit_value',
        'period',
        'is_hard_limit',
        'is_active',
        'reason',
        'metadata',
        'starts_at',
        'ends_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
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
