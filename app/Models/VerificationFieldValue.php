<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class VerificationFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'verification_field_id',
        'value',
        'flag',
        'user_id',
        'verification_request_id'
    ];

    public function verification_field()
    {
        return $this->belongsTo(VerificationField::class, 'verification_field_id', 'id');
    }

    // Relationship with the VerificationRequest model
    public function verification_request()
    {
        return $this->belongsTo(VerificationRequest::class, 'verification_request_id', 'id');
    }


//    public function getValueAttribute($value) {
//        try {
//            return array_values(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
//        } catch (JsonException) {
//            return $value;
//        }
//    }

    public function getValueAttribute($value) {
        if(str_contains($value,"verification_field_files")){
            return url(Storage::url($value));
        }
        return $value;
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
