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
         'image',
         'canonical_url',
         'site_name',
         'search_path',
         'knowledge_graph_type',
         'organization_name',
         'organization_logo',
         'organization_phone',
         'organization_email',
         'organization_address',
         'social_profiles_json',
         'og_title',
         'og_description',
         'og_image',
         'og_type',
         'twitter_title',
         'twitter_description',
         'twitter_image',
         'twitter_card',
         'robots_index',
         'robots_follow',
         'robots_noarchive',
         'robots_nosnippet',
         'schema_json',
    ];

    protected $casts = [
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'robots_noarchive' => 'boolean',
        'robots_nosnippet' => 'boolean',
    ];

    protected $appends = ['translated_title','translated_description','translated_keywords'];

    private function resolveMediaUrl(?string $value): ?string
    {
        $rawValue = trim((string) ($value ?? ''));
        if ($rawValue === '') {
            return null;
        }

        if (str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://')) {
            return $rawValue;
        }

        return url(Storage::url($rawValue));
    }

    public function getImageAttribute($image) {
        return $this->resolveMediaUrl($image);
    }

    public function getOgImageAttribute($image)
    {
        return $this->resolveMediaUrl($image);
    }

    public function getTwitterImageAttribute($image)
    {
        return $this->resolveMediaUrl($image);
    }

    public function getOrganizationLogoAttribute($logo)
    {
        return $this->resolveMediaUrl($logo);
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
