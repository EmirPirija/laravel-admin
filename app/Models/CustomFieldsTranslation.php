<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CustomFieldsTranslation extends Model
{
    use HasFactory;
     protected $table = 'custom_fields_translations';
     protected $fillable = [
        'custom_field_id',
        'language_id',
        'name',
        'value',
    ];
    /**
     * Get the custom field that owns this translation.
     */
    public function customField()
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * Get the language for this translation.
     */
    public function language()
    {
        return $this->belongsTo(Language::class);
    }

     public function getValueAttribute($value) {
        try {
            return array_values(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return $value;
        }
    }
}
