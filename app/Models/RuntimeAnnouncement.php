<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RuntimeAnnouncement extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'title',
        'message',
        'level',
        'placement',
        'channel',
        'is_active',
        'is_dismissible',
        'roles',
        'user_ids',
        'rollout_percentage',
        'action_label',
        'action_url',
        'metadata',
        'starts_at',
        'ends_at',
        'priority',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_dismissible' => 'boolean',
        'roles' => 'array',
        'user_ids' => 'array',
        'rollout_percentage' => 'integer',
        'metadata' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
