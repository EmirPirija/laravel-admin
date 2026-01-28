<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempMedia extends Model
{
    protected $table = 'temp_media';

    protected $fillable = [
        'user_id',
        'type',   // 'image' | 'video'
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'size'    => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
