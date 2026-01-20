<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StateTranslation extends Model
{
    use HasFactory;
     protected $fillable = ['state_id', 'language_id', 'name'];

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
