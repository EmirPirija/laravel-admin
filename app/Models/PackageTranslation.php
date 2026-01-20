<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'language_id',
        'name',
        'description',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
