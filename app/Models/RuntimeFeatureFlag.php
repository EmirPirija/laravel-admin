<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RuntimeFeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled',
        'rollout_mode',
        'rollout_percentage',
        'roles',
        'user_ids',
        'payload',
        'conditions',
        'variant',
        'priority',
        'starts_at',
        'ends_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'rollout_percentage' => 'integer',
        'roles' => 'array',
        'user_ids' => 'array',
        'payload' => 'array',
        'conditions' => 'array',
        'priority' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
