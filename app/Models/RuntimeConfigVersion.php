<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RuntimeConfigVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'last_hash',
        'updated_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'updated_by' => 'integer',
    ];
}
