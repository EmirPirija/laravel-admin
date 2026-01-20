<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Package extends Model {
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'discount_in_percentage',
        'final_price',
        'duration',
        'item_limit',
        'type',
        'icon',
        'description',
        'status',
        'ios_product_id',
        'package_type',
        'membership_tier',
        'features',
    ];
    protected $appends = ['translated_name', 'translated_description'];
    protected $casts = [
        'features' => 'array',
    ];

    public function user_purchased_packages() {
        return $this->hasMany(UserPurchasedPackage::class);
    }
    public function translations()
    {
        return $this->hasMany(PackageTranslation::class);
    }

    public function getIconAttribute($icon) {
        if (!empty($icon)) {
            return url(Storage::url($icon));
        }
        return $icon;
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('name', 'LIKE', $search)
                ->orWhere('price', 'LIKE', $search)
                ->orWhere('discount_in_percentage', 'LIKE', $search)
                ->orWhere('final_price', 'LIKE', $search)
                ->orWhere('duration', 'LIKE', $search)
                ->orWhere('item_limit', 'LIKE', $search)
                ->orWhere('type', 'LIKE', $search)
                ->orWhere('description', 'LIKE', $search)
                ->orWhere('status', 'LIKE', $search)
                ->orWhere('created_at', 'LIKE', $search)
                ->orWhere('updated_at', 'LIKE', $search);
        });
        return $query;
    }
    public function getTranslatedNameAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        if (!empty($languageCode) && $this->relationLoaded('translations')) {
            // NOTE : This code can be done in Cache
            $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

            $translation = $this->translations->first(static function ($data) use ($language) {
                return $data->language_id == $language->id;
            });

           return !empty($translation?->name) ? $translation->name : $this->name;
        }

        return $this->name;
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
