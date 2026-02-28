<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthEventLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_type',
        'endpoint',
        'ip_address',
        'identifier',
        'status',
        'meta',
        'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
