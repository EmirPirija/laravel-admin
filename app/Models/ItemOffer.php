<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ItemOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'seller_id',
        'buyer_id',
        'amount',
        'muted_by',
    ];

    protected $casts = [
        'muted_by' => 'array',
    ];

    protected $appends = ['is_muted'];

    public function getIsMutedAttribute()
    {
        if (!Auth::check()) {
            return false;
        }

        $mutedList = $this->muted_by ?? [];
        return in_array(Auth::id(), $mutedList);
    }

    public function item()
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    // ✅ PLURAL + matches $conversation->chats()
    public function chats()
    {
        return $this->hasMany(Chat::class, 'item_offer_id');
    }

    public function scopeOwner($query)
    {
        $id = Auth::id();
        if (!$id) {
            return $query->whereRaw('1=0');
        }

        return $query->where('seller_id', $id)
            ->orWhere('buyer_id', $id);
    }
}
