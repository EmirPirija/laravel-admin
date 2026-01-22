<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable {
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, HasPermissions;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'type',
        'firebase_id',
        'profile',
        'address',
        'notification',
        'country_code',
        'show_personal_details',
        'is_verified',
        'auto_approve_item',
        'region_code',
        'last_seen',
        'phone_verified_at',
        'total_sales',
        'response_time_avg',
        'seller_level',
        'avatar_key',
        'use_svg_avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'badges',
    ];

    public function getBadgesAttribute(): array
{
    $badges = [];
    
    // ✅ Telefon verificiran
    if (!empty($this->mobile) && strlen($this->mobile) > 5) {
        $badges[] = [
            'id' => 'phone_verified',
            'name' => 'Telefon verificiran',
            'icon' => 'phone',
        ];
    }
    
    // 📧 Email verificiran
    if (!empty($this->email_verified_at) || !empty($this->email)) {
        $badges[] = [
            'id' => 'email_verified', 
            'name' => 'Email verificiran',
            'icon' => 'email',
        ];
    }
    
    // 📅 Godine na platformi
    if ($this->created_at) {
        $years = $this->created_at->diffInYears(now());
        
        if ($years >= 5) {
            $badges[] = [
                'id' => 'years_5',
                'name' => '5+ godina član',
                'icon' => 'star',
            ];
        } elseif ($years >= 2) {
            $badges[] = [
                'id' => 'years_2',
                'name' => '2+ godine član',
                'icon' => 'clock',
            ];
        } elseif ($years >= 1) {
            $badges[] = [
                'id' => 'years_1',
                'name' => '1+ godina član',
                'icon' => 'clock',
            ];
        }
    }
    
    // 💰 Broj prodaja
    $sales = $this->total_sales ?? 0;
    
    if ($sales >= 100) {
        $badges[] = [
            'id' => 'sales_100',
            'name' => '100+ prodaja',
            'icon' => 'trophy',
        ];
    } elseif ($sales >= 50) {
        $badges[] = [
            'id' => 'sales_50',
            'name' => '50+ prodaja',
            'icon' => 'bag',
        ];
    } elseif ($sales >= 10) {
        $badges[] = [
            'id' => 'sales_10',
            'name' => '10+ prodaja',
            'icon' => 'bag',
        ];
    }
    
    // ⭐ Vrhunski ocijenjen
    $avgRating = $this->sellerReview()->avg('ratings');
    $reviewCount = $this->sellerReview()->count();
    
    if ($avgRating >= 4.5 && $reviewCount >= 10) {
        $badges[] = [
            'id' => 'top_rated',
            'name' => 'Vrhunski ocijenjen',
            'icon' => 'star',
        ];
    }
    
    return $badges;
}

public function badges()
{
    return $this->belongsToMany(Badge::class, 'user_badges')
        ->withTimestamps()
        ->withPivot('earned_at');
}

public function points()
{
    return $this->hasOne(UserPoints::class);
}

public function pointsHistory()
{
    return $this->hasMany(PointsHistory::class);
}

 
// 5. Metoda za ažuriranje seller levela (pozovi nakon prodaje)
public function updateSellerLevel(): void
{
    $sales = $this->total_sales ?? 0;
    $avgRating = $this->sellerReview()->avg('ratings') ?? 0;
    $months = $this->created_at ? $this->created_at->diffInMonths(now()) : 0;
    
    $level = 'bronze';
    
    if ($sales >= 200 && $avgRating >= 4.8 && $months >= 12) {
        $level = 'platinum';
    } elseif ($sales >= 50 && $avgRating >= 4.5 && $months >= 6) {
        $level = 'gold';
    } elseif ($sales >= 10 && $avgRating >= 4.0) {
        $level = 'silver';
    }
    
    $this->update(['seller_level' => $level]);
}

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_seen' => 'datetime',
        'phone_verified_at' => 'datetime',
        'total_sales' => 'integer',
        'response_time_avg' => 'integer',
        'use_svg_avatar' => 'boolean',
    ];


    public function getProfileAttribute($image) {
        if (!empty($image) && !filter_var($image, FILTER_VALIDATE_URL)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function items() {
        return $this->hasMany(Item::class);
    }

    public function sellerReview() {
        return $this->hasMany(SellerRating::class , 'seller_id');
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        return $query->where(function ($q) use ($search) {
            $q->orWhere('email', 'LIKE', $search)
                ->orWhere('mobile', 'LIKE', $search)
                ->orWhere('name', 'LIKE', $search)
                ->orWhere('type', 'LIKE', $search)
                ->orWhere('notification', 'LIKE', $search)
                ->orWhere('firebase_id', 'LIKE', $search)
                ->orWhere('address', 'LIKE', $search)
                ->orWhere('created_at', 'LIKE', $search)
                ->orWhere('updated_at', 'LIKE', $search);
        });
    }

    public function user_reports() {
        return $this->hasMany(UserReports::class);
    }

    public function fcm_tokens() {
        return $this->hasMany(UserFcmToken::class);
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

    public function membership()
{
    return $this->hasOne(UserMembership::class);
}

public function membershipTransactions()
{
    return $this->hasMany(MembershipTransaction::class);
}

// Helper metoda da vidiš tier
public function getTier()
{
    return $this->membership->tier ?? 'free';
}

// Provjeri da li user ima Pro
public function isPro()
{
    return $this->membership && 
           $this->membership->tier === 'pro' && 
           $this->membership->isActive();
}

// Provjeri da li user ima Shop
public function isShop()
{
    return $this->membership && 
           $this->membership->tier === 'shop' && 
           $this->membership->isActive();
}

}
