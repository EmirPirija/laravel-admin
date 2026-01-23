<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
 
class Sale extends Model
{
    use HasFactory;
 
    protected $fillable = [
        'item_id',
        'seller_id',
        'buyer_id',
        'quantity',
        'unit_price',
        'total_price',
        'receipt_url',
        'note',
        'status',
    ];
 
    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];
 
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
 
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
 
    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}