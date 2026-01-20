<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'user_id',
        'question',
        'answer',
        'answered_at',
        'answered_by',
        'likes_count',
        'is_reported',
        'is_hidden',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'is_reported' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function answeredBy()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function likes()
    {
        return $this->hasMany(ItemQuestionLike::class, 'question_id');
    }

    public function reports()
    {
        return $this->hasMany(ItemQuestionReport::class, 'question_id');
    }
}
