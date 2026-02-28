<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteLiveSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'visitor_id',
        'session_id',
        'user_id',
        'page_path',
        'page_url',
        'page_title',
        'referrer_url',
        'device_type',
        'ip_address',
        'user_agent',
        'heartbeat_count',
        'first_seen_at',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

