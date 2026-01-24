<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
 
class BihEntity extends Model
{
    protected $table = 'bih_entities';
 
    protected $fillable = [
        'code',
        'name',
        'short_name',
        'is_active',
    ];
 
    protected $casts = [
        'is_active' => 'boolean',
    ];
 
    public function regions(): HasMany
    {
        return $this->hasMany(BihRegion::class, 'entity_id');
    }
 
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}