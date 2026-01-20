<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model {
    use HasFactory;

    protected $fillable = [
        'name',
        'country_id',
        'state_id',
        'city_id',
        'state_code',
        'latitude',
        'longitude'
    ];

    protected $appends = ['translated_name'];
    protected $with = ['translations'];
    public function city() {
        return $this->belongsTo(City::class);
    }

    public function state() {
        return $this->belongsTo(State::class);
    }

    public function country() {
        return $this->belongsTo(Country::class);
    }
    public function translations()
    {
        return $this->hasMany(AreaTranslation::class);
    }
    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if ($column == "city.name") {
                    $query->whereHas('city', function ($query) use ($value) {
                        $query->where('city_id', $value);
                    });
                } elseif ($column == "state.name") {
                    $query->whereHas('state', function ($query) use ($value) {
                        $query->where('state_id', $value);
                    });
                } elseif ($column == "country.name") {
                    $query->whereHas('country', function ($query) use ($value) {
                        $query->where('country_id', $value);
                    });
                } else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;

    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('id', 'LIKE', $search)
                ->orWhere('name', 'LIKE', $search)
                ->orWhere('city_id', 'LIKE', $search)
                ->orWhere('state_id', 'LIKE', $search)
                ->orWhere('state_code', 'LIKE', $search)
                ->orWhere('country_id', 'LIKE', $search)
                ->orWhereHas('country', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                })->orWhereHas('state', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                })->orWhereHas('city', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                });
        });
        return $query;
    }
    public function getTranslatedNameAttribute()
    {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        $language = Language::where('code', $languageCode)->first();

        if ($language) {
            $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
            $translation = $translations->firstWhere('language_id', $language->id);
            return $translation?->name ?? $this->name;
        }

        return $this->name;
    }
}
