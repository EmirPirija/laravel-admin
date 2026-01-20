<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AreaTranslation extends Model
{
    use HasFactory;
     protected $fillable = ['area_id', 'language_id', 'name'];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
