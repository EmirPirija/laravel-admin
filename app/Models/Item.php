<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
 
class Item extends Model {
    use HasFactory, SoftDeletes;
 
    protected $fillable = [
        'category_id',
        'name',
        'price',
        'description',
        'latitude',
        'longitude',
        'address',
        'contact',
        'show_only_to_premium',
        'video_link',
        'status',
        'rejected_reason',
        'user_id',
        'image',
        'country',
        'state',
        'city',
        'area_id',
        'all_category_ids',
        'slug',
        'sold_to',
        'expiry_date',
        'min_salary',
        'max_salary',
        'is_edited_by_admin',
        'admin_edit_reason',
        'package_id',
        'region_code',
        'is_on_sale',
        'old_price',
        'price_history',
        'video',
        'video_thumbnail',
        'video_duration',
    ];
 
    /**
     * casts za automatsku konverziju tipova
     */
    protected $casts = [
        'is_on_sale' => 'boolean',
        'old_price' => 'decimal:2',
        'price_history' => 'array',
        'expiry_date' => 'datetime',
        'video',
        'video_thumbnail',
        'video_duration',
    ];
 
    protected $appends = [
        'translated_name', 
        'translated_description', 
        'discount_percentage',
        'savings',
    ];
 
    protected $with = ['translations'];
    

    // Accessors
public function getVideoAttribute($value): ?string
{
    return $value ? url(\Storage::url($value)) : null;
}
 
public function getVideoThumbnailAttribute($value): ?string
{
    return $value ? url(\Storage::url($value)) : null;
}
 
    /**
     * 🔥 Boot method - automatski prati promjene cijena
     */
    protected static function boot()
    {
        parent::boot();
 
        // Prije update-a, sačuvaj staru cijenu u historiju
        static::updating(function ($item) {
            // Ako se cijena mijenja, dodaj staru cijenu u historiju
            if ($item->isDirty('price') && $item->getOriginal('price')) {
                $oldPrice = $item->getOriginal('price');
                $newPrice = $item->price;
                
                // Samo ako su cijene različite
                if ($oldPrice != $newPrice) {
                    $history = $item->price_history ?? [];
                    
                    $history[] = [
                        'id' => count($history) + 1,
                        'price' => (float) $oldPrice,
                        'created_at' => now()->toISOString(),
                    ];
                    
                    // Zadrži samo zadnjih 20 zapisa
                    $item->price_history = array_slice($history, -20);
                }
            }
        });
 
        // Nakon kreiranja, inicijaliziraj historiju cijena
        static::created(function ($item) {
            if ($item->price) {
                $item->price_history = [
                    [
                        'id' => 1,
                        'price' => (float) $item->price,
                        'created_at' => now()->toISOString(),
                    ]
                ];
                $item->saveQuietly(); // Spremi bez pokretanja eventi
            }
        });
    }
 
    /**
     * 🔥 Accessor za izračun postotka popusta
     */
    public function getDiscountPercentageAttribute(): int
    {
        if (!$this->is_on_sale || !$this->old_price || !$this->price) {
            return 0;
        }
        
        if ((float)$this->old_price <= (float)$this->price) {
            return 0;
        }
 
        return (int) round((((float)$this->old_price - (float)$this->price) / (float)$this->old_price) * 100);
    }
 
    /**
     * 🔥 Helper metoda za dobijanje uštede
     */
    public function getSavingsAttribute(): float
    {
        if (!$this->is_on_sale || !$this->old_price || !$this->price) {
            return 0;
        }
        
        return max(0, (float)$this->old_price - (float)$this->price);
    }
 
    // Relationships
    public function user() {
        return $this->belongsTo(User::class);
    }
 
    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }
 
    public function gallery_images() {
        return $this->hasMany(ItemImages::class);
    }
 
    public function custom_fields() {
        return $this->hasManyThrough(
            CustomField::class, CustomFieldCategory::class,
            'category_id', 'id', 'category_id', 'custom_field_id'
        );
    }
 
    public function item_custom_field_values() {
        return $this->hasMany(ItemCustomFieldValue::class,'item_id');
    }
 
    public function featured_items() {
        return $this->hasMany(FeaturedItems::class)->onlyActive();
    }
 
    public function favourites() {
        return $this->hasMany(Favourite::class);
    }
 
    public function item_offers() {
        return $this->hasMany(ItemOffer::class);
    }
 
    public function user_reports() {
        return $this->hasMany(UserReports::class);
    }
 
    public function sliders(): MorphMany {
        return $this->morphMany(Slider::class, 'model');
    }
 
    public function area() {
        return $this->belongsTo(Area::class);
    }
 
    public function review() {
        return $this->hasMany(SellerRating::class);
    }
 
    public function job_applications() {
        return $this->hasMany(JobApplication::class);
    }
 
    // Accessors
    public function getImageAttribute($image) {
        return !empty($image) ? url(Storage::url($image)) : $image;
    }
 
    public function getStatusAttribute($value)
    {
        if ($this->deleted_at) {
            return "inactive";
        }
        if ($this->expiry_date && $this->expiry_date < Carbon::now()) {
            return "expired";
        }
        return $value;
    }
 
    public function translations()
    {
        return $this->hasMany(ItemTranslation::class);
    }
 
    // Scopes
    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        return $query->where(function ($q) use ($search) {
            $q->orWhere('name', 'LIKE', $search)
                ->orWhere('description', 'LIKE', $search)
                ->orWhere('price', 'LIKE', $search)
                ->orWhere('image', 'LIKE', $search)
                ->orWhere('latitude', 'LIKE', $search)
                ->orWhere('longitude', 'LIKE', $search)
                ->orWhere('address', 'LIKE', $search)
                ->orWhere('contact', 'LIKE', $search)
                ->orWhere('show_only_to_premium', 'LIKE', $search)
                ->orWhere('status', 'LIKE', $search)
                ->orWhere('video_link', 'LIKE', $search)
                ->orWhere('clicks', 'LIKE', $search)
                ->orWhere('user_id', 'LIKE', $search)
                ->orWhere('country', 'LIKE', $search)
                ->orWhere('state', 'LIKE', $search)
                ->orWhere('city', 'LIKE', $search)
                ->orWhere('category_id', 'LIKE', $search)
                ->orWhereHas('category', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                })->orWhereHas('user', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
               })->orWhereHas('translations', function ($q) use ($search) {
                $q->where('name', 'LIKE', $search)
                  ->orWhere('description', 'LIKE', $search)
                  ->orWhere('address', 'LIKE', $search)
                  ->orWhere('city', 'LIKE', $search)
                  ->orWhere('state', 'LIKE', $search)
                  ->orWhere('country', 'LIKE', $search);
            });
        });
    }
 
    public function scopeOwner($query) {
        if (Auth::user()->hasRole('User')) {
            return $query->where('user_id', Auth::user()->id);
        }
        return $query;
    }
 
    public function scopeApproved($query) {
        return $query->where('status', 'approved');
    }
 
    public function scopeNotOwner($query) {
        return $query->where('user_id', '!=', Auth::user()->id);
    }
 
    public function scopeSort($query, $column, $order) {
        if ($column == "user_name") {
            return $query->leftJoin('users', 'users.id', '=', 'items.user_id')
                ->orderBy('users.name', $order)
                ->select('items.*');
        }
        return $query->orderBy($column, $order);
    }
 
    public function scopeFilter($query, $filterObject)
    {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if ($column == 'status') {
                    if ($value == 'inactive') {
                        $query->whereNotNull('deleted_at')->where(function ($q) {
                            $q->whereNull('expiry_date')
                              ->orWhere('expiry_date', '>=', Carbon::now());
                        });
                    } elseif ($value == 'expired') {
                        $query->whereNotNull('expiry_date')
                              ->where('expiry_date', '<', Carbon::now())->whereNull('deleted_at');
                    } else {
                        if (in_array($value, ['review', 'approved', 'rejected', 'sold out', 'soft rejected', 'permanent rejected', 'resubmitted'])) {
                            $query->whereNull('deleted_at')
                                  ->where(function ($q) {
                                      $q->whereNull('expiry_date')
                                        ->orWhere('expiry_date', '>=', Carbon::now());
                                  });
                        }
                        $query->where($column, $value);
                    }
                } elseif ($column == 'featured_status') {
                    if ($value == 'featured') {
                        $query->whereHas('featured_items');
                    } elseif ($value == 'premium') {
                        $query->whereDoesntHave('featured_items');
                    }
                } elseif ($column == 'is_on_sale') {
                    // 🔥 Filter za akcijske oglase
                    $query->where('is_on_sale', filter_var($value, FILTER_VALIDATE_BOOLEAN));
                } elseif (in_array($column, ['country', 'state', 'city'])) {
                    $query->where($column, 'LIKE', '%' . $value . '%');
                } else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;
    }
 
    public function scopeOnlyNonBlockedUsers($query) {
        $blocked_user_ids = BlockUser::where('user_id', Auth::user()->id)
            ->pluck('blocked_user_id');
        return $query->whereNotIn('user_id', $blocked_user_ids);
    }
 
    public function scopeGetNonExpiredItems($query) {
        return $query->where(function($query) {
            $query->where('expiry_date', '>', Carbon::now())->orWhereNull('expiry_date');
        });
    }
 
    public function scopeIsJobCategory($query, $isJob = 1)
    {
        return $query->whereHas('category', function ($q) use ($isJob) {
            $q->where('is_job_category', $isJob);
        });
    }
 
    public function scopePriceOptional($query, $isJob = 1)
    {
        return $query->whereHas('category', function ($q) use ($isJob) {
            $q->where('price_optional', $isJob);
        });
    }
 
    /**
     * 🔥 Scope za samo akcijske oglase
     */
    public function scopeOnSale($query)
    {
        return $query->where('is_on_sale', true)
                     ->whereNotNull('old_price')
                     ->whereColumn('old_price', '>', 'price');
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
 
    public function getTranslatedDescriptionAttribute()
    {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();
        $language = Language::where('code', $languageCode)->first();
 
        if ($language) {
            $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
            $translation = $translations->firstWhere('language_id', $language->id);
            return $translation?->description ?? $this->description;
        }
 
        return $this->description;
    }
}