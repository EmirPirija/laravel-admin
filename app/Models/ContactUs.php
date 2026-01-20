<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactUs extends Model
{
    use HasFactory;

    protected $table = 'contact_us';
    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message'
    ];

    public function scopeSort($query, $column, $order) {
        $query = $query->orderBy($column, $order);
        return $query->select('contact_us.*');
    }
}
