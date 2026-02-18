<?php
 
namespace App\Http\Resources;
 
use App\Models\City;
use App\Models\Language;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use JsonSerializable;
use Throwable;
 
class ItemCollection extends ResourceCollection {
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array|Arrayable|JsonSerializable
     * @throws Throwable
     */
    public function toArray(Request $request) {
        try {
            $response = [];
 
            // Get current language once
            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLanguage = Language::where('code', $contentLangCode)->first();
            $currentLangId = $currentLanguage->id ?? 1;
            $defaultLangId = 1;

            $normalizePlacement = static function ($raw) {
                $value = strtolower(trim((string) $raw));
                return in_array($value, ['category', 'home', 'category_home'], true) ? $value : null;
            };

            $requestedPlacementContext = $normalizePlacement(
                $request->input('placement') ?: $request->input('positions')
            );
            $derivedPlacementContext = null;
            if (! $requestedPlacementContext) {
                $currentPage = strtolower(trim((string) $request->input('current_page')));
                if ($currentPage === 'home') {
                    $derivedPlacementContext = 'home';
                } elseif ($request->filled('category_id') || $request->filled('category_slug')) {
                    $derivedPlacementContext = 'category';
                }
            }
            $placementContext = $requestedPlacementContext ?: $derivedPlacementContext;
 
            foreach ($this->collection as $key => $collection) {
 
                // Base response
                $response[$key] = $collection->toArray();

                $publishedAt = !empty($collection->created_at) ? Carbon::parse($collection->created_at) : null;
                if ($collection->relationLoaded('translations') && !empty($collection->translations)) {
                    foreach ($collection->translations as $translation) {
                        if (empty($translation?->created_at)) continue;
                        $translationCreatedAt = Carbon::parse($translation->created_at);
                        if (!$publishedAt || $translationCreatedAt->lt($publishedAt)) {
                            $publishedAt = $translationCreatedAt;
                        }
                    }
                }
                $response[$key]['published_at'] = $publishedAt?->toIso8601String();
                // Keep API backward compatible for clients still reading created_at as publish date.
                if ($publishedAt) {
                    $response[$key]['created_at'] = $publishedAt->toIso8601String();
                }
 
                // 🔥 AKCIJA/SALE POLJA
                $response[$key]['is_on_sale'] = (bool) $collection->is_on_sale;
                $response[$key]['old_price'] = $collection->old_price ? (float) $collection->old_price : null;
                $response[$key]['discount_percentage'] = $collection->discount_percentage;
                $response[$key]['savings'] = $collection->savings;
                $response[$key]['price_history'] = $collection->price_history ?? [];

                $response[$key]['video'] = $collection->video;
                $response[$key]['video_thumbnail'] = $collection->video_thumbnail;
                $response[$key]['video_duration'] = $collection->video_duration;
                $response[$key]['add_video_to_story'] = (bool) ($collection->add_video_to_story ?? false);
 
                // Feature status + placement metadata (context-aware)
                $statusValue = strtolower((string) ($collection->status ?? ''));
                $isStatusEligibleForFeatured = in_array($statusValue, ['approved', 'featured', 'reserved'], true);
                $featuredRows = ($isStatusEligibleForFeatured && $collection->relationLoaded('featured_items'))
                    ? $collection->featured_items
                    : collect();

                $sortFeaturedRows = static function ($rows) {
                    return $rows->sortByDesc(function ($row) {
                        return Carbon::parse(
                            $row->updated_at
                                ?: $row->created_at
                                ?: $row->end_date
                                ?: $row->start_date
                                ?: '1970-01-01 00:00:00'
                        )->getTimestamp();
                    });
                };
                $resolveFeaturedRowPlacement = static function ($row) use ($normalizePlacement) {
                    if (! $row) {
                        return null;
                    }

                    return $normalizePlacement($row->placement ?: $row->positions);
                };

                $sortedFeaturedRows = $featuredRows->isNotEmpty() ? $sortFeaturedRows($featuredRows) : collect();
                $activeFeaturedItem = $sortedFeaturedRows->first();
                $isFeatureAny = ! is_null($activeFeaturedItem);

                $isFeatureHome = $sortedFeaturedRows->contains(function ($row) use ($resolveFeaturedRowPlacement) {
                    return in_array($resolveFeaturedRowPlacement($row), ['home', 'category_home'], true);
                });
                $isFeatureCategory = $sortedFeaturedRows->contains(function ($row) use ($resolveFeaturedRowPlacement) {
                    return in_array($resolveFeaturedRowPlacement($row), ['category', 'category_home'], true);
                });

                if ($placementContext === 'home') {
                    $activeFeaturedItem = $sortedFeaturedRows->first(function ($row) use ($resolveFeaturedRowPlacement) {
                        return in_array($resolveFeaturedRowPlacement($row), ['home', 'category_home'], true);
                    }) ?: $activeFeaturedItem;
                } elseif ($placementContext === 'category') {
                    $activeFeaturedItem = $sortedFeaturedRows->first(function ($row) use ($resolveFeaturedRowPlacement) {
                        return in_array($resolveFeaturedRowPlacement($row), ['category', 'category_home'], true);
                    }) ?: $activeFeaturedItem;
                } elseif ($placementContext === 'category_home') {
                    $activeFeaturedItem = $sortedFeaturedRows->first(function ($row) use ($resolveFeaturedRowPlacement) {
                        return $resolveFeaturedRowPlacement($row) === 'category_home';
                    }) ?: $activeFeaturedItem;
                }

                $featuredPlacement = $resolveFeaturedRowPlacement($activeFeaturedItem) ?: ($isFeatureAny ? 'category_home' : null);

                $isFeatureForContext = $isFeatureAny;
                if ($placementContext === 'home') {
                    $isFeatureForContext = $isFeatureHome;
                } elseif ($placementContext === 'category') {
                    $isFeatureForContext = $isFeatureCategory;
                } elseif ($placementContext === 'category_home') {
                    $isFeatureForContext = $isFeatureAny && $featuredPlacement === 'category_home';
                }

                $featuredEndDate = null;
                if (! empty($activeFeaturedItem?->end_date)) {
                    $featuredEndDate = Carbon::parse($activeFeaturedItem->end_date)->endOfDay();
                }

                $featuredSecondsLeft = null;
                $featuredDaysLeft = null;
                $featuredHoursLeft = null;
                if ($featuredEndDate) {
                    $featuredSecondsLeft = Carbon::now()->diffInSeconds($featuredEndDate, false);
                    if ($featuredSecondsLeft > 0) {
                        $featuredDaysLeft = (int) ceil($featuredSecondsLeft / 86400);
                        $featuredHoursLeft = (int) ceil($featuredSecondsLeft / 3600);
                    } else {
                        $featuredSecondsLeft = 0;
                        $featuredDaysLeft = 0;
                        $featuredHoursLeft = 0;
                    }
                }

                $response[$key]['is_feature'] = $isFeatureForContext;
                $response[$key]['is_feature_any'] = $isFeatureAny;
                $response[$key]['is_feature_home'] = $isFeatureHome;
                $response[$key]['is_feature_category'] = $isFeatureCategory;
                $response[$key]['featured_placement'] = $featuredPlacement;
                $response[$key]['positions'] = $activeFeaturedItem?->positions ?? $featuredPlacement;
                $response[$key]['featured_duration_days'] = $activeFeaturedItem?->duration_days;
                $response[$key]['featured_start_date'] = $activeFeaturedItem?->start_date;
                $response[$key]['featured_end_date'] = $activeFeaturedItem?->end_date;
                $response[$key]['featured_expires_at'] = $featuredEndDate?->toIso8601String();
                $response[$key]['featured_seconds_left'] = $featuredSecondsLeft;
                $response[$key]['featured_days_left'] = $featuredDaysLeft;
                $response[$key]['featured_hours_left'] = $featuredHoursLeft;
 
                // Favourites
                if ($collection->relationLoaded('favourites')) {
                    $response[$key]['total_likes'] = $collection->favourites->count();
                    $response[$key]['is_liked'] = Auth::check()
                        ? $collection->favourites->where('item_id', $collection->id)->where('user_id', Auth::id())->count() > 0
                        : false;
                }
 
                // User info
                if ($collection->relationLoaded('user') && !is_null($collection->user)) {
                    $response[$key]['user'] = $collection->user;
                    $response[$key]['user']['reviews_count'] = $collection->user->sellerReview()->count();
                    $response[$key]['user']['average_rating'] = $collection->user->sellerReview->avg('ratings');
                    if ($collection->user->show_personal_details == 0) {
                        $response[$key]['user']['mobile'] = '';
                        $response[$key]['user']['country_code'] = '';
                        $response[$key]['user']['email'] = '';
                    }
                }
 
                // Load city once
                $city = City::with(['translations','state','country'])
                            ->where('name', $collection->city)
                            ->whereHas('state', fn($q) => $q->where('name', $collection->state))
                            ->first();
 
                // Translated item
                $translatedItem = [
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'address' => $collection->address,
                    'rejected_reason' => $collection->rejected_reason ?? null,
                    'admin_edit_reason' => $collection->admin_edit_reason ?? null,
                    'city' => $city->translated_name ?? $collection->city,
                    'state' => $city->state->translated_name ?? $collection->state,
                    'country' => $city->country->translated_name ?? $collection->country,
                ];
 
                if ($currentLanguage && $collection->relationLoaded('translations')) {
                    $translation = $collection->translations->firstWhere('language_id', $currentLangId);
                    if ($translation) {
                        $translatedItem = [
                            'name' => $translation->name,
                            'description' => $translation->description,
                            'address' => $translation->address,
                            'rejected_reason' => $translation->rejected_reason,
                            'admin_edit_reason' => $translation->admin_edit_reason,
                            'city' => $city->translated_name ?? $collection->city,
                            'state' => $city->state->translated_name ?? $collection->state,
                            'country' => $city->country->translated_name ?? $collection->country,
                        ];
                    }
                }
 
                $response[$key]['translated_item'] = $translatedItem;
                $response[$key]['translated_area'] = $collection->area->translated_name ?? "";
                $response[$key]['translated_city'] = $city?->translated_name ?? $collection->city;
                $response[$key]['translated_state'] = $city->state->translated_name ?? $collection->state;
                $response[$key]['translated_country'] = $city->country->translated_name ?? $collection->country;
                $response[$key]['translated_address'] = 
                (!empty($response[$key]['translated_area']) ? $response[$key]['translated_area'] . ', ' : '') .
                $response[$key]['translated_city'] . ', ' .
                $response[$key]['translated_state'] . ', ' .
                $response[$key]['translated_country'];
 
                // Custom fields
                if ($collection->relationLoaded('item_custom_field_values')) {
                    $response[$key]['custom_fields'] = [];
                    $response[$key]['translated_custom_fields'] = [];
                    $response[$key]['all_translated_custom_fields'] = [];
 
                    $grouped = $collection->item_custom_field_values->groupBy('custom_field_id');
 
                    foreach ($grouped as $customFieldId => $fieldValues) {
                        $default = $fieldValues->firstWhere('language_id', $defaultLangId);
                        $translated = $currentLanguage ? $fieldValues->firstWhere('language_id', $currentLangId) : null;
 
                        // Default fields
                        if ($default && $default->relationLoaded('custom_field') && !empty($default->custom_field)) {
                            $field = $default->custom_field;
                            $tempRow = $field->toArray();
                            $tempRow['value'] = $field->type === "fileinput"
                                ? (!empty($default->value) ? [url(Storage::url($default->value))] : [])
                                : (is_array($default->value) ? $default->value : json_decode($default->value, true));
                            $tempRow['custom_field_value'] = $default->toArray();
                            unset($tempRow['custom_field_value']['custom_field']);
                            $tempRow['translated_selected_values'] = $this->resolveTranslatedSelectedValues($field, $default);
                            $response[$key]['custom_fields'][] = $tempRow;
                        }
 
                        // Translated fields
                        $activeField = $translated ?? $default;
                        if ($activeField && $activeField->relationLoaded('custom_field') && !empty($activeField->custom_field)) {
                            $field = $activeField->custom_field;
                            $tempRow = $field->toArray();
                            $tempRow['value'] = $field->type === "fileinput"
                                ? (!empty($activeField->value) ? url(Storage::url($activeField->value)) : '')
                                : (is_array($activeField->value) ? $activeField->value : json_decode($activeField->value, true));
                            $tempRow['custom_field_value'] = $activeField->toArray();
                            unset($tempRow['custom_field_value']['custom_field']);
                            $tempRow['language_id'] = $activeField->language_id;
                            $tempRow['translated_selected_values'] = $this->resolveTranslatedSelectedValues($field, $activeField);
                            $response[$key]['translated_custom_fields'][] = $tempRow;
                        }
 
                        // All translated custom fields
                        foreach ($fieldValues as $fieldValue) {
                            if ($fieldValue->relationLoaded('custom_field') && !empty($fieldValue->custom_field)) {
                                $field = $fieldValue->custom_field;
                                $tempRow = $field->toArray();
                                
                                if ($field->type === "fileinput") {
                                    if (!empty($fieldValue->value)) {
                                        $rawValue = is_string($fieldValue->value) && (str_starts_with($fieldValue->value, '[') || str_starts_with($fieldValue->value, '{'))
                                            ? json_decode($fieldValue->value, true)
                                            : $fieldValue->value;
                                    
                                        if (is_array($rawValue)) {
                                            $value = array_map(fn($file) => url(Storage::url($file)), $fieldValue->value);
                                        } else {
                                            $value = url(Storage::url($fieldValue->value));
                                        }
                                    } else {
                                        $value = '';
                                    }
                                } else {
                                    $value = is_array($fieldValue->value)
                                        ? $fieldValue->value
                                        : json_decode($fieldValue->value, true);
                                }
                    
                                $tempRow['value'] = $value;
 
                                $tempRow['custom_field_value'] = $tempRow;
                                unset($tempRow['custom_field_value']['custom_field']);
                                $tempRow['language_id'] = $fieldValue->language_id;
                                $tempRow['translated_selected_values'] = $this->resolveTranslatedSelectedValues($field, $fieldValue);
                                $response[$key]['all_translated_custom_fields'][] = $tempRow;
                            }
                        }
                    }
 
                    unset($response[$key]['item_custom_field_values']);
                }
 
                // Item Offers, Reports, Purchases, Job Applications
                $response[$key]['is_already_offered'] = $collection->relationLoaded('item_offers') && Auth::check()
                    ? $collection->item_offers->where('item_id', $collection->id)->where('buyer_id', Auth::id())->count() > 0
                    : false;
 
                $response[$key]['is_already_reported'] = $collection->relationLoaded('user_reports') && Auth::check()
                    ? $collection->user_reports->where('user_id', Auth::id())->count() > 0
                    : false;
 
                $response[$key]['is_purchased'] = Auth::check() && $collection->sold_to == Auth::id() ? 1 : 0;
 
                $response[$key]['is_already_job_applied'] = $collection->relationLoaded('job_applications') && Auth::check()
                    ? $collection->job_applications->where('item_id', $collection->id)->where('user_id', Auth::id())->count() > 0
                    : false;
            }
 
            // Featured and normal rows
            $featuredRows = [];
            $normalRows = [];
            foreach ($response as $value) {
                $value['is_feature'] ? $featuredRows[] = $value : $normalRows[] = $value;
            }
 
            $response = array_merge($featuredRows, $normalRows);
            $totalCount = count($response);
 
            if ($this->resource instanceof AbstractPaginator) {
                return [
                    ...$this->resource->toArray(),
                    'data' => $response,
                    'total_item_count' => $totalCount,
                ];
            }
 
            return $response;
 
        } catch (Throwable $th) {
            throw $th;
        }
    }
 
    public function resolveTranslatedSelectedValues($field, $fieldValue)
    {
        $type = $field->type ?? null;
        $values = $fieldValue->value ?? null;
        $allPossibleValues = $field->values ?? [];
        $selected = [];
 
        $contentLangCode = request()->header('Content-Language') ?? app()->getLocale();
        $currentLanguage = Language::where('code', $contentLangCode)->first();
        $currentLangId =  $currentLanguage->id ?? 1;
 
        $translatedValues = [];
        if (!empty($field->translations)) {
            $translation = collect($field->translations)->firstWhere('language_id', $currentLangId);
            $translatedValues = $translation['value'] ?? [];
        }
 
        if (in_array($type, ['checkbox', 'radio', 'dropdown'])) {
            $actualValues = is_array($values) ? $values : json_decode($values, true);
            if (!is_array($actualValues)) {
                $actualValues = [$actualValues];
            }
 
            foreach ($actualValues as $val) {
                $index = array_search($val, $allPossibleValues);
                $translatedVal = (is_array($translatedValues) && $index !== false && isset($translatedValues[$index]))
                    ? $translatedValues[$index]
                    : $val;
 
                $selected[] = $translatedVal;
            }
        } elseif (in_array($type, ['textbox', 'number'])) {
            $actualValue = is_array($values) ? ($values[0] ?? '') : $values;
            $selected[] = $actualValue;
        }
 
        return $selected;
    }
}
