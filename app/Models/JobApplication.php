<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class JobApplication extends Model
{
    use HasFactory;
    protected $fillable = [
        'item_id',
        'user_id',
        'recruiter_id',
        'full_name',
        'email',
        'mobile',
        'resume',
        'status',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function recruiter()
    {
        return $this->belongsTo(User::class);
    }
    public function getResumeAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }
}
