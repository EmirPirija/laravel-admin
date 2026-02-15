<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'products_requested',
        'products_imported',
        'products_failed',
        'category_id',
        'source_url',
        'source_urls_json',
        'feed_format',
        'status',
        'message',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'source_urls_json' => 'array',
        'meta' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
