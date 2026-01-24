<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
class BihMunicipality extends Model
{
    protected $table = 'bih_municipalities';
 
    protected $fillable = [
        'code',
        'name',
        'region_id',
        'type',
        'latitude',
        'longitude',
        'population',
        'postal_code',
        'is_active',
        'is_popular',
    ];
 
    protected $casts = [
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];
 
    public function region(): BelongsTo
    {
        return $this->belongsTo(BihRegion::class, 'region_id');
    }
 
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
 
    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }
 
    public function scopeByRegion($query, $regionId)
    {
        return $query->where('region_id', $regionId);
    }
 
    public function scopeSearch($query, $term)
    {
        return $query->where('name', 'LIKE', "%{$term}%");
    }
 
    // Helper za punu adresu
    public function getFullAddressAttribute(): string
    {
        $parts = [$this->name];
        
        if ($this->region) {
            $parts[] = $this->region->name;
            if ($this->region->entity) {
                $parts[] = $this->region->entity->short_name ?? $this->region->entity->name;
            }
        }
        
        $parts[] = 'Bosna i Hercegovina';
        
        return implode(', ', $parts);
    }
}