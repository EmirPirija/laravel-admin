<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    use HasFactory;

    protected $fillable=[
        'question',
        'answer'

    ];
    protected $appends = ['translated_question','translated_answer'];
    public function translations()
    {
        return $this->hasMany(FaqTranslation::class);
    }

    public function getTranslation($languageId)
    {
        return $this->translations->where('language_id', $languageId)->first();
    }
     public function getTranslatedQuestionAttribute()
    {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();

        if (empty($languageCode)) {
            return $this->question;
        }

        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();
        if (!$language) {
            return $this->question;
        }

        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $translation = $translations->first(function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->question) ? $translation->question : $this->question;
    }

    public function getTranslatedAnswerAttribute()
    {
        $languageCode = request()->header('Content-Language') ?? app()->getLocale();

        if (empty($languageCode)) {
            return $this->answer;
        }

        $language = Language::select(['id', 'code'])->where('code', $languageCode)->first();
        if (!$language) {
            return $this->answer;
        }

        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $translation = $translations->first(function ($data) use ($language) {
            return $data->language_id == $language->id;
        });

        return !empty($translation?->answer) ? $translation->answer : $this->answer;
    }
}
