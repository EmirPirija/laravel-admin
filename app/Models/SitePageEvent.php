<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SitePageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'visitor_id',
        'session_id',
        'user_id',
        'event_type',
        'page_path',
        'page_url',
        'page_title',
        'referrer_url',
        'device_type',
        'ip_address',
        'user_agent',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

