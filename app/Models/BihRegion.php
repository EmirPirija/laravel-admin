<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
 
class BihRegion extends Model
{
    protected $table = 'bih_regions';
 
    protected $fillable = [
        'code',
        'name',
        'entity_id',
        'type',
        'is_active',
    ];
 
    protected $casts = [
        'is_active' => 'boolean',
    ];
 
    public function entity(): BelongsTo
    {
        return $this->belongsTo(BihEntity::class, 'entity_id');
    }
 
    public function municipalities(): HasMany
    {
        return $this->hasMany(BihMunicipality::class, 'region_id');
    }
 
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
 
    public function scopeByEntity($query, $entityId)
    {
        return $query->where('entity_id', $entityId);
    }
}