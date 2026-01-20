<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettingTranslation extends Model
{
    protected $fillable = [
        'setting_id', 'language_id', 'translated_value',
    ];

    public function setting()
    {
        return $this->belongsTo(Setting::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
