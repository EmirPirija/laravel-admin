<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogTranslation extends Model
{
    use HasFactory;
    protected $fillable = ['blog_id', 'language_id', 'title', 'description', 'tags'];

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
   public function getTagsAttribute($value)
     {
            if (is_array($value)) {
                return $value;
            }

            if (!empty($value)) {
                return explode(',', $value);
            }

            return [];
    }
    public function setTagsAttribute($value) {
        if (is_array($value)) {
            $cleaned = array_map(fn($tag) => trim($tag, " \t\n\r\0\x0B\"'"), $value);
            $this->attributes['tags'] = implode(',', $cleaned);
        } elseif (is_string($value)) {
            $this->attributes['tags'] = trim($value, " \t\n\r\0\x0B\"'");
        } else {
            $this->attributes['tags'] = '';
        }
    }


}
