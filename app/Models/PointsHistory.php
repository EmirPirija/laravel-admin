<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointsHistory extends Model
{
    protected $fillable = [
        'user_id',
        'points',
        'action',
        'description',
        'reference_type',
        'reference_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
