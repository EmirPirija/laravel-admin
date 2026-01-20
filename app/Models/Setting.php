<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Str;

class Setting extends Model {
    use HasFactory;

    public $table = "settings";

    protected $fillable = [
        'name',
        'value',
        'type'
    ];
    protected $hidden = [
        'updated_at',
        'deleted_at'
    ];
    public function translations()
        {
            return $this->hasMany(SettingTranslation::class, 'setting_id');
        }


    public function getValueAttribute($value) {
        if (isset($this->attributes['type']) && $this->attributes['type'] == "file") {

            if (!empty($value)) {
                /*Note : Because this is default logo so storage url will not work*/
                if (Str::contains($value,'assets')) {
                    return asset($value);
                }
                return url(Storage::url($value));
            }
            return "";
        }
        return $value;
    }
    public function getTranslatedValueAttribute()
    {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
    
        if (!empty($languageCode) && $this->relationLoaded('translations')) {
            // Try to fetch requested language
            $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();
    
            // If not found, fall back to default language
            if (!$language) {
                $defaultLanguageCode = Setting::where('name', "default_language")->value('value') ?? null;
    
                if ($defaultLanguageCode) {
                    $language = Language::select(['id', 'code'])
                        ->where('code', $defaultLanguageCode)
                        ->first();
                }
            }
    
            // Try to fetch translation if language exists
            if ($language) {
                $translation = $this->translations->first(function ($data) use ($language) {
                    return $data->language_id == $language->id;
                });
    
                return !empty($translation?->translated_value) ? $translation->translated_value : $this->value;
            }
        }
    
        return $this->value;
    }
}
