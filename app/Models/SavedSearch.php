<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SavedSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'context',
        'name',
        'query_string',
        'query_hash',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
