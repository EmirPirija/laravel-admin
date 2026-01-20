<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CustomField extends Model {
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'image',
        'required',
        'status',
        'priority',
        'values',
        'min_length',
        'max_length',
        'dependent_on',      // ← NOVO
        'parent_mapping',    // ← NOVO
    ];
    
    protected $hidden = ['created_at', 'updated_at'];

    protected $appends = ['translated_name', 'translated_value', 'parent_field_name'];  // ← NOVO: dodao parent_field_name

    protected $casts = [
        'parent_mapping' => 'array',  // ← NOVO: Automatski JSON decode/encode
    ];

    // Default sortiranje po prioritetu
    protected static function booted()
    {
        static::addGlobalScope('priority', function ($query) {
            $query->orderBy('priority', 'asc');
        });
    }

    public function custom_field_category() {
        return $this->hasMany(CustomFieldCategory::class, 'custom_field_id');
    }

    public function translations() {
        return $this->hasMany(CustomFieldsTranslation::class);
    }

    public function item_custom_field_values() {
        return $this->hasMany(ItemCustomFieldValue::class);
    }

    public function categories() {
        return $this->belongsToMany(Category::class, CustomFieldCategory::class);
    }

    // ← NOVO: Relacija za parent field
    public function parentField()
    {
        return $this->belongsTo(CustomField::class, 'dependent_on');
    }

    // ← NOVO: Relacija za zavisna polja
    public function dependentFields()
    {
        return $this->hasMany(CustomField::class, 'dependent_on');
    }

    public function getValuesAttribute($value) {
        try {
            return array_values(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return $value;
        }
    }

    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('name', 'LIKE', $search)
                ->orWhere('type', 'LIKE', $search)
                ->orWhere('values', 'LIKE', $search)
                ->orWhere('status', 'LIKE', $search)
                ->orWhereHas('categories', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                });
        });
        return $query;
    }

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if ($column == "category_names") {
                    $query->whereHas('custom_field_category', function ($query) use ($value) {
                        $query->where('category_id', $value);
                    });
                } elseif ($column == "type") {
                    $query->where('type', $value);
                } else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;
    }

    public function getTranslatedNameAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        if ($this->relationLoaded('translations')) {
            $language = Language::where('code', $languageCode)->first();
            if ($language) {
                $translation = $this->translations->first(static function ($data) use ($language) {
                    return $data->language_id == $language->id;
                });
                return $translation->name ?? $this->name;
            }
        }
        return $this->name;
    }

    public function getTranslatedValueAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        if ($this->relationLoaded('translations')) {
            $language = Language::where('code', $languageCode)->first();
            if ($language) {
                $translation = $this->translations->first(fn($t) => $t->language_id == $language->id);
                try {
                    return $translation && $translation->value
                        ? $translation->value
                        : $this->values;
                } catch (\Throwable) {
                    return $this->values;
                }
            }
        }
        return $this->values;
    }

    // ← NOVO: Getter za ime parent polja (korisno za frontend)
    public function getParentFieldNameAttribute()
    {
        if ($this->dependent_on && $this->relationLoaded('parentField')) {
            return $this->parentField?->name;
        }
        return null;
    }
}