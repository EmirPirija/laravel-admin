<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedUserListItem extends Model
{
  protected $table = 'saved_user_list_items';

  protected $fillable = [
    'list_id',
    'user_id',
    'saved_user_id',
    'note',
    'last_viewed_at',
  ];

  protected $casts = [
    'last_viewed_at' => 'datetime',
  ];

  public function list(): BelongsTo
  {
    return $this->belongsTo(SavedUserList::class, 'list_id');
  }

  public function savedUser(): BelongsTo
  {
    return $this->belongsTo(User::class, 'saved_user_id');
  }

  public function owner(): BelongsTo
  {
    return $this->belongsTo(User::class, 'user_id');
  }
}
