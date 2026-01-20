<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportReason extends Model {
    use HasFactory;

    protected $fillable = [
        'reason'
    ];
     protected $appends = ['translated_reason'];
    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhere('reason', 'LIKE', $search)
                ->orWhere('created_at', 'LIKE', $search)
                ->orWhere('updated_at', 'LIKE', $search);
        });
        return $query;
    }
    public function translations() {
        return $this->hasMany(ReportReasonTranslation::class);
    }
    public function getTranslatedReasonAttribute() {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();

        if (empty($languageCode)) {
            return $this->name;
        }
        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();

        if (!$language) {
            return $this->reason;
        }
        $nameTranslations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $translation = $nameTranslations->first(static function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->reason) ? $translation->reason : $this->reason;
    }
}
