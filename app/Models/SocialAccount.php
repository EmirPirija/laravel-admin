<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_user_id',
        'account_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'page_id',
        'page_access_token',
        'instagram_account_id',
        'has_shop_access',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'has_shop_access' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'page_access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }
        return $this->token_expires_at->isPast();
    }

    public function isTokenExpiringSoon(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }
        return $this->token_expires_at->isBefore(now()->addDays(7));
    }
}
