<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Storage;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;


class Category extends Model {
    use HasFactory, HasRecursiveRelationships;

    protected $fillable = [
        'name',
        'parent_category_id',
        'image',
        'slug',
        'status',
        'description',
        'is_job_category',
        'price_optional',
        'sequence'
    ];


    public function getParentKeyName() {
        return 'parent_category_id';
    }

    protected $appends = ['translated_name', 'translated_description'];
    protected $with = ['translations'];

    public function subcategories() {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    public function custom_fields() {
        return $this->hasMany(CustomFieldCategory::class);
    }

    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function items() {
        return $this->hasMany(Item::class);
    }

    public function approved_items() {
        return $this->hasMany(Item::class)->where('status', 'approved');
    }

    public function getAllItemsCountAttribute()
    {
        $totalItems = $this->items()->where('status', 'approved')->getNonExpiredItems()->count();
        foreach ($this->subcategories as $subcategory) {
            $totalItems += $subcategory->all_items_count;
        }
        return $totalItems;
    }
    

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        return $query->where(function ($q) use ($search) {
            $q->orWhere('name', 'LIKE', $search)
                ->orWhere('description', 'LIKE', $search)
                ->orWhereHas('translations', function ($q) use ($search) {
                    $q->where('description', 'LIKE', $search);
                });
        });
    }

    public function slider(): MorphOne {
        return $this->morphOne(Slider::class, 'model');
    }

    public function translations() {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function getTranslatedNameAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        if (!empty($languageCode)) {
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
        if (!empty($languageCode)) {
            // NOTE : This code can be done in Cache
            $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

            $translation = $this->translations->first(static function ($data) use ($language) {
                return $data->language_id == $language->id;
            });

           return !empty($translation?->description) ? $translation->description : $this->description;
        }

        return $this->description;
    }

    public function parent() { return $this->belongsTo(Category::class, 'parent_category_id'); }

    public function getFullPathAttribute()
    {
        $names   = [];
        $current = $this;
        $visited = [];

        while ($current) {
            if (in_array($current->id, $visited, true)) {
                break; // prevent loop
            }
            $visited[] = $current->id;

            $names[]  = $current->name;
            $current  = $current->parent;
        }

        return implode(' > ', array_reverse($names));
    }
    public function getItemsGroupedByStatusAttribute()
    {
        $counts = [];

        // Count items in this category
        $items = $this->items()->get();
        foreach ($items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }

        // Include subcategories recursively
        foreach ($this->subcategories as $subcategory) {
            $subCounts = $subcategory->items_grouped_by_status;
            foreach ($subCounts as $status => $count) {
                $counts[$status] = ($counts[$status] ?? 0) + $count;
            }
        }

        return $counts;
    }

      public function getOtherItemsCountAttribute()
    {
        $totalItems = $this->items()->where('status', '!=', 'approved')->count();
        foreach ($this->subcategories as $subcategory) {
            $totalItems += $subcategory->other_items_count; 
        }

        return $totalItems;
    }
}
