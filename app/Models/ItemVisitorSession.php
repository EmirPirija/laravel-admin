<?php
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class ItemVisitorSession extends Model
{
    protected $fillable = [
        'item_id', 'visitor_id', 'user_id', 'device_type',
        'source', 'source_detail', 'referrer_url', 'ip_address', 'user_agent'
    ];
 
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}