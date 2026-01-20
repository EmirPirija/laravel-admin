<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Blog extends Model {
    use HasFactory;

    protected $dates = ['created_at', 'updated_at'];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'image',
        'tags'
    ];
    protected $appends = ['translated_title', 'translated_description', 'translated_tags'];

    public function category() {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }

        public function getTagsAttribute($value) {
            if (is_array($value)) {
                return $value;
            }

            if (is_string($value)) {
                return explode(',', $value);
            }

            return [];
        }


    public function setTagsAttribute($value) {
    if (is_array($value)) {
        $cleaned = array_map(fn($tag) => trim($tag, " \t\n\r\0\x0B\"'"), $value);
        $this->attributes['tags'] = implode(',', $cleaned);
    } elseif (is_string($value)) {
        $this->attributes['tags'] = trim($value, " \t\n\r\0\x0B\"'");
    } else {
        $this->attributes['tags'] = '';
    }
}



    public function translations() {
        return $this->hasMany(BlogTranslation::class);
    }
    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('title', 'LIKE', $search)
                ->orWhere('description', 'LIKE', $search)
                ->orWhere('tags', 'LIKE', $search);
        });
        return $query;
    }

    public function scopeSort($query, $column, $order) {
        if ($column == "category_name") {
            return $query->leftJoin('categories', 'categories.id', '=', 'blogs.category_id')
                ->orderBy('categories.name', $order)
                ->select('blogs.*');
        }
        return $query->orderBy($column, $order);
    }
    public function getTranslatedTitleAttribute() {
    $languageCode = request()->header('Content-Language') ?? app()->getLocale();

    if (!empty($languageCode) && $this->relationLoaded('translations')) {
        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

        $translation = $this->translations->first(static function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->title) ? $translation->title : $this->title;
    }

    return $this->title;
}

public function getTranslatedTagsAttribute() {
    $languageCode = request()->header('Content-Language') ?? app()->getLocale();

    if (!empty($languageCode) && $this->relationLoaded('translations')) {
        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

        $translation = $this->translations->first(static function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        if (!empty($translation?->tags)) {
            if (is_array($translation->tags)) {
                return array_map(fn($tag) => trim($tag, " \t\n\r\0\x0B\"'"), $translation->tags);
            }

            if (is_string($translation->tags)) {
                return array_map(fn($tag) => trim($tag, " \t\n\r\0\x0B\"'"), explode(',', $translation->tags));
            }
        }
    }

    return array_map(fn($tag) => trim($tag, " \t\n\r\0\x0B\"'"), $this->tags ?? []);
}

public function getTranslatedDescriptionAttribute() {
    $languageCode = request()->header('Content-Language') ?? app()->getLocale();

    if (!empty($languageCode) && $this->relationLoaded('translations')) {
        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

        $translation = $this->translations->first(static function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->description) ? $translation->description : $this->description;
    }

    return $this->description;
}

}
