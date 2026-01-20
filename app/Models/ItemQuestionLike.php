<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemQuestionLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'user_id',
    ];

    public function question()
    {
        return $this->belongsTo(ItemQuestion::class, 'question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
