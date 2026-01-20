<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerRating extends Model
{
    use HasFactory ,SoftDeletes;

    protected $fillable = [
        'review',
        'ratings',
        'seller_id',
        'buyer_id',
        'item_id',
        'report_status',
        'report_reason',
        'report_rejected_reason',
        'images'
    ];

    protected $casts = [
        'images' => 'array', 
        'ratings' => 'float',
    ];

     public function seller() {
         return $this->belongsTo(User::class,'seller_id');
     }

    public function buyer() {
        return $this->belongsTo(User::class,'buyer_id');
    }

    public function item() {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                $query->where((string)$column, (string)$value);
            }
        }
        return $query;
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        return $query->where(function ($q) use ($search) {
            $q->orWhere('review', 'LIKE', $search)
                ->orWhere('ratings', 'LIKE', $search)
                ->orWhere('id', 'LIKE', $search)
                ->orWhere('report_status', 'LIKE', $search)
                ->orWhere('report_reason', 'LIKE', $search)
                ->orWhere('report_rejected_reason', 'LIKE', $search)
                ->orWhere('seller_id', 'LIKE', $search)
                ->orWhere('buyer_id', 'LIKE', $search)
                ->orWhere('item_id', 'LIKE', $search)
                ->orWhereHas('item', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                })->orWhereHas('seller', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                })->orWhereHas('buyer', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                });
        });
    }

    public function scopeSort($query, $column, $order)
    {
        if ($column == "item_name") {
            $query->leftJoin('items', 'items.id', '=', 'seller_ratings.item_id')
                ->orderBy('items.name', $order);
        }
        else if ($column == "seller_name") {
            $query->leftJoin('users as seller_users', 'seller_users.id', '=', 'seller_ratings.seller_id')
                ->orderBy('seller_users.name', $order);
        }
        else if ($column == "buyer_name") {
            $query->leftJoin('users as buyer_users', 'buyer_users.id', '=', 'seller_ratings.buyer_id')
                ->orderBy('buyer_users.name', $order);
        }
        else {
            $query->orderBy($column, $order);
        }
        return $query->select('seller_ratings.*');
    }
}
