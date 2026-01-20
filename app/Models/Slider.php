<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Slider extends Model {
    use HasFactory;

    protected $fillable = ['image', 'item_id', 'third_party_link', 'sequence','name', 'sold_out'];

    public function item() {
        return $this->belongsTo(Item::class);
    }

    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('sequence', 'LIKE', $search)
            ->orWhere('model_type', 'LIKE', $search)
                ->orWhere('third_party_link', 'LIKE', $search)
                ->orWhere('model_id', 'LIKE', $search)
                ->orWhereHas('model', function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search);
                });
        });
        return $query;
    }

    public function scopeSort($query, $column, $order) {
        if ($column == 'model_name') {
            $query->when(request('model_type') === 'App\\Models\\Item', function ($q) use ($order) {
                $q->leftJoin('items', 'items.id', '=', 'sliders.model_id')
                  ->orderBy('items.name', $order);
            });
            $query->when(request('model_type') === 'App\\Models\\Category', function ($q) use ($order) {
                $q->leftJoin('categories', 'categories.id', '=', 'sliders.model_id')
                  ->orderBy('categories.name', $order);
            });
            return $query->select('sliders.*');
        }
        elseif ($column == "item_name") {
            $query = $query->leftjoin('items', 'items.id', '=', 'sliders.item_id')->orderBy('items.name', $order);
        } else {
            $query = $query->orderBy($column, $order);
        }
        return $query->select('sliders.*');
    }
    public function categories() {
        return $this->hasOne(Category::class);
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

}
