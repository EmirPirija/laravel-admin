<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemTranslation extends Model
{
    use HasFactory;

   protected $fillable = [
        'item_id',
        'language_id',
        'name',
        'slug',
        'description',
        'address',
        'rejected_reason',
        'admin_edit_reason',
    ];


    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
