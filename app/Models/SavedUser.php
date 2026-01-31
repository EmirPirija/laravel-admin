<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedUser extends Model
{
  protected $table = 'saved_users';

  protected $fillable = [
    'user_id',
    'saved_user_id',
  ];

  public function savedUser()
  {
    return $this->belongsTo(User::class, 'saved_user_id');
  }
}
