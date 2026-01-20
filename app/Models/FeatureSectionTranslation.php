<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureSectionTranslation extends Model
{
    use HasFactory;
    protected $fillable = ['feature_section_id', 'language_id', 'name', 'description'];

    public function featureSection()
    {
        return $this->belongsTo(FeatureSection::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
