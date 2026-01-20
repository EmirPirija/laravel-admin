<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NumberOtp extends Model
{
    use HasFactory;
    protected $table = 'number_otps';

    protected $fillable = [
        'number',
        'otp',
        'expire_at',
        'attempts'
    ];

    public function setOtpAttribute($value) {
        $this->attributes['otp'] = base64_encode($value);
    }

    public function getOtpAttribute($value) {
        return base64_decode($value);
    }
}
