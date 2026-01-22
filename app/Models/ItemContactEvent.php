<?php
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class ItemContactEvent extends Model
{
    protected $fillable = [
        'item_id', 'user_id', 'contact_type', 'ip_address'
    ];
 
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
 
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}