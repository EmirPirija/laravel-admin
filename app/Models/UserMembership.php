<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserMembership extends Model
{
    use HasFactory;

    protected $table = 'user_memberships';

    protected $fillable = [
        'user_id',
        'tier_id',
        'tier',
        'started_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Relacija sa User modelom
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relacija sa MembershipTier modelom
    public function membershipTier()
    {
        return $this->belongsTo(MembershipTier::class, 'tier_id');
    }

    // Provjeri da li je membership aktivan
    public function isActive()
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Ako nema expires_at, membership je trajni (lifetime)
        if (!$this->expires_at) {
            return true;
        }

        // Provjeri da li je istekao
        return Carbon::now()->lte($this->expires_at);
    }

    // Provjeri da li je membership istekao
    public function isExpired()
    {
        if (!$this->expires_at) {
            return false;
        }

        return Carbon::now()->gt($this->expires_at);
    }
}
