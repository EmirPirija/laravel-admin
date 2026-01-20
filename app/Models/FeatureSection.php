<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureSection extends Model {
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'sequence',
        'filter',
        'value',
        'style',
        'min_price',
        'max_price',
        'description'
    ];
      protected $appends = ['translated_name', 'translated_description'];
    public function category() {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
    public function translations()
        {
            return $this->hasMany(FeatureSectionTranslation::class);
        }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('title', 'LIKE', $search)
                ->orWhere('sequence', 'LIKE', $search)
                ->orWhere('filter', 'LIKE', $search)
                ->orWhere('value', 'LIKE', $search)
                ->orWhere('style', 'LIKE', $search)
                ->orWhere('min_price', 'LIKE', $search)
                ->orWhere('max_price', 'LIKE', $search)
                ->orWhere('created_at', 'LIKE', $search)
                ->orWhere('updated_at', 'LIKE', $search)
                ->orWhere('description', 'LIKE', $search);
        });
        return $query;
    }
    public function getTranslatedNameAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        
        if (!empty($languageCode) && $this->relationLoaded('translations')) {
            // NOTE : This code can be done in Cache
            $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();
            
    
            if (!$language) {
                $defaultLanguageCode = Setting::where('name', "default_language")->value('value');
                $language = Language::where('code', $defaultLanguageCode)->first();
            }

            $translation = $this->translations->first(static function ($data) use ($language) {
                return $data->language_id == $language->id;
            });

           return !empty($translation?->name) ? $translation->name : $this->title;
        }

        return $this->name;
    }
        public function getTranslatedDescriptionAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();

        if (!empty($languageCode) && $this->relationLoaded('translations')) {
            $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();
    if (!$language) {
                $defaultLanguageCode = Setting::where('name', "default_language")->value('value');
                $language = Language::where('code', $defaultLanguageCode)->first();
            }
            $translation = $this->translations->first(static function ($data) use ($language) {
                return $data->language_id == $language->id;
            });

            return !empty($translation?->description) ? $translation->description : $this->description;
        }

        return $this->description;
    }
}
