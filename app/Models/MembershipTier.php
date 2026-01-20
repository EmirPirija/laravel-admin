<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipTier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'price',
        'duration_days',
        'features',
        'permissions',
        'is_active',
        'order'
    ];

    protected $casts = [
        'features' => 'array',
        'permissions' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function userMemberships()
    {
        return $this->hasMany(UserMembership::class, 'tier_id');
    }

    public function transactions()
    {
        return $this->hasMany(MembershipTransaction::class, 'tier_id');
    }
}
