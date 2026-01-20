<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoSettingsTranslation extends Model
{
    use HasFactory;
      protected $fillable = [
        'seo_setting_id',
        'language_id',
        'title',
        'description',
        'keywords',
    ];

    public function seoSetting()
    {
        return $this->belongsTo(SeoSetting::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
