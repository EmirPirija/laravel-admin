<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model {
    use HasFactory;

    protected $fillable = [
        "id",
        "name",
        "state_code",
        "latitude",
        "longitude",
        "type",
        "country_id",
        "country_code",
        "fips_code",
        "iso2",
        "type",
        "latitude",
        "longitude",
        "created_at",
        "updated_at",
        "flag",
        "wikiDataId",
    ];

    protected $appends = ['translated_name'];
    protected $with = ['translations'];
    public function country() {
        return $this->belongsTo(Country::class);
    }
    public function cities() {
        return $this->hasMany(City::class);
    }
    public function translations()
{
    return $this->hasMany(StateTranslation::class);
}

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('id', 'LIKE', $search)
                ->orWhere('name', 'LIKE', $search)
                ->orWhere('country_id', 'LIKE', $search)
                ->orWhere('state_code', 'LIKE', $search)
                ->orWhereHas('country', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                });
        });
        return $query;
    }

    public function scopeSort($query, $column, $order) {
        if ($column == "country_name") {
            $query = $query->leftjoin('countries', 'countries.id', '=', 'states.country_id')->orderBy('countries.name', $order);
        } else {
            $query = $query->orderBy($column, $order);
        }

        return $query->select('states.*');
    }
    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
              if($column == "country_name") {
                    $query->whereHas('country', function ($query) use ($value) {
                        $query->where('country_id', $value);
                    });
                }
                else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
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

