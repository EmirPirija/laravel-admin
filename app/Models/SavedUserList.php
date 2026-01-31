<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedUserList extends Model
{
  protected $table = 'saved_user_lists';

  protected $fillable = [
    'user_id',
    'name',
    'is_default',
    'sort_order',
  ];

  protected $casts = [
    'is_default' => 'boolean',
    'sort_order' => 'integer',
  ];

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function items(): HasMany
  {
    return $this->hasMany(SavedUserListItem::class, 'list_id');
  }
}
