<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPoints extends Model
{
    protected $fillable = [
        'user_id',
        'total_points',
        'level',
        'level_name',
        'points_to_next_level',
        'current_level_points'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
