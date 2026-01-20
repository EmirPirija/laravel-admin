<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Storage;

class SeoSetting extends Model
{
    use HasFactory;

    protected $fillable =[
         'page',
         'title',
         'description',
         'keywords',
         'image'
    ];
    protected $appends = ['translated_title','translated_description','translated_keywords'];
    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }
    public function scopeSort($query, $column, $order) {

        $query = $query->orderBy($column, $order);

        return $query->select('seo_settings.*');
    }

    public function translations()
    {
        return $this->hasMany(SeoSettingsTranslation::class);
    }

    public function getTranslation($languageId = null)
    {
        $languageId = $languageId ?: Language::where('code', request()->header('Content-Language') ?? app()->getLocale())->value('id');

        return $this->translations->where('language_id', $languageId)->first();
    }

    public function getTranslatedTitleAttribute()
    {
        return $this->getTranslation()?->title ?? $this->title;
    }

    public function getTranslatedDescriptionAttribute()
    {
        return $this->getTranslation()?->description ?? $this->description;
    }

    public function getTranslatedKeywordsAttribute()
    {
        return $this->getTranslation()?->keywords ?? $this->keywords;
    }

}
