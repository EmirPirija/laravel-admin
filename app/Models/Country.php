<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model {
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'iso3',
        'numeric_code',
        'iso2',
        'phonecode',
        'capital',
        'currency',
        'currency_name',
        'currency_symbol',
        'tld',
        'native',
        'region',
        'region_id',
        'subregion',
        'subregion_id',
        'nationality',
        'timezones',
        'translations',
        'latitude',
        'longitude',
        'emoji',
        'emojiU',
        'created_at',
        'updated_at',
        'flag',
        'wikiDataId'
    ];
    protected $appends = ['translated_name'];
    protected $with = ['translations'];
    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('id', 'LIKE', $search)
                ->orWhere('name', 'LIKE', $search)
                ->orWhere('numeric_code', 'LIKE', $search)
                ->orWhere('phonecode', 'LIKE', $search);
        });
        return $query;
    }
    public function states() {
        return $this->hasMany(State::class);
    }
    public function nameTranslations()
    {
        return $this->hasMany(CountryTranslation::class);
    }

   public function getTranslatedNameAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();

        if (empty($languageCode)) {
            return $this->name;
        }

        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

        if (!$language) {
            return $this->name;
        }
        $nameTranslations = $this->relationLoaded('nameTranslations')
            ? $this->nameTranslations
            : $this->nameTranslations()->get();

        $translation = $nameTranslations->first(static function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->name) ? $translation->name : $this->name;
    }

    public function translations()
    {
        return $this->hasMany(CountryTranslation::class);
    }

}
