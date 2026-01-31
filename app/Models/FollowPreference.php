<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowPreference extends Model
{
  protected $table = 'follow_preferences';

  protected $fillable = [
    'user_id',
    'followed_user_id',
    'frequency',
    'enabled',
    'last_checked_at',
    'last_notified_at',
  ];

  protected $casts = [
    'enabled' => 'boolean',
    'last_checked_at' => 'datetime',
    'last_notified_at' => 'datetime',
  ];

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class, 'user_id');
  }

  public function followedUser(): BelongsTo
  {
    return $this->belongsTo(User::class, 'followed_user_id');
  }
}
