<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SellerSettingsController extends Controller
{
    // Mora matchati frontend listu (avatar_id)
    private const AVATAR_IDS = [
        'lmx-01','lmx-02','lmx-03','lmx-04','lmx-05','lmx-06',
        'lmx-07','lmx-08','lmx-09','lmx-10','lmx-11','lmx-12',
    ];

    public function getSettings()
    {
        try {
            $user = Auth::user();

            $settings = SellerSetting::firstOrCreate(
                ['user_id' => $user->id],
                [
                    // ✅ default avatar
                    'avatar_id' => self::AVATAR_IDS[0],

                    'show_phone' => true,
                    'show_email' => true,
                    'show_whatsapp' => false,
                    'show_viber' => false,
                    'preferred_contact_method' => 'message',

                    'response_time' => 'auto',
                    'accepts_offers' => true,

                    'auto_reply_enabled' => false,
                    'vacation_mode' => false,
                ]
            );

            // Ako je avatar_id prazan iz nekog razloga
            if (empty($settings->avatar_id)) {
                $settings->avatar_id = self::AVATAR_IDS[0];
                $settings->save();
            }

            return response()->json([
                'error' => false,
                'data' => $settings,
                'message' => 'Settings fetched successfully'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'error' => true,
                'message' => 'Error fetching settings'
            ], 500);
        }
    }

    public function updateSettings(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                // ✅ avatar
                'avatar_id' => ['nullable', 'string', 'max:50', Rule::in(self::AVATAR_IDS)],

                'show_phone' => 'boolean',
                'show_email' => 'boolean',
                'show_whatsapp' => 'boolean',
                'show_viber' => 'boolean',
                'whatsapp_number' => 'nullable|string|max:20',
                'viber_number' => 'nullable|string|max:20',
                'preferred_contact_method' => 'nullable|in:message,phone,whatsapp,viber,email',

                'business_hours' => 'nullable', // string ili array
                'response_time' => 'nullable|in:auto,instant,few_hours,same_day,few_days',
                'accepts_offers' => 'boolean',

                'auto_reply_enabled' => 'boolean',
                'auto_reply_message' => 'nullable|string|max:300',
                'vacation_mode' => 'boolean',
                'vacation_message' => 'nullable|string|max:200',

                'business_description' => 'nullable|string|max:500',
                'return_policy' => 'nullable|string|max:300',
                'shipping_info' => 'nullable|string|max:300',

                'social_facebook' => 'nullable|url|max:255',
                'social_instagram' => 'nullable|url|max:255',
                'social_tiktok' => 'nullable|url|max:255',
                'social_youtube' => 'nullable|url|max:255',
                'social_website' => 'nullable|url|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            $user = Auth::user();
            $settings = SellerSetting::firstOrCreate(
                ['user_id' => $user->id],
                ['avatar_id' => self::AVATAR_IDS[0]]
            );

            // Osnovna polja
            $updateData = $request->only([
                'avatar_id',

                'show_phone',
                'show_email',
                'show_whatsapp',
                'show_viber',
                'whatsapp_number',
                'viber_number',
                'preferred_contact_method',

                'response_time',
                'accepts_offers',

                'auto_reply_enabled',
                'auto_reply_message',

                'vacation_mode',
                'vacation_message',

                'business_description',
                'return_policy',
                'shipping_info',

                'social_facebook',
                'social_instagram',
                'social_tiktok',
                'social_youtube',
                'social_website',
            ]);

            // ✅ business_hours (NE json_encode) -> uvijek array ili null
            if ($request->has('business_hours')) {
                $bh = $request->input('business_hours');
            
                if (is_string($bh)) {
                    $decoded = json_decode($bh, true);
                    $bh = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
                }
            
                $updateData['business_hours'] = is_array($bh) ? $bh : null;
            }
            
            

            // Normalizacija boolean-a
            $boolFields = [
                'show_phone','show_email','show_whatsapp','show_viber',
                'accepts_offers','auto_reply_enabled','vacation_mode'
            ];
            foreach ($boolFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = (bool) $request->input($field);
                }
            }

            // Avatar fallback
            if (array_key_exists('avatar_id', $updateData) && empty($updateData['avatar_id'])) {
                $updateData['avatar_id'] = self::AVATAR_IDS[0];
            }

            $settings->update($updateData);

            return response()->json([
                'error' => false,
                'data' => $settings->fresh(),
                'message' => 'Settings updated successfully'
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'error' => true,
                'message' => 'Error updating settings'
            ], 500);
        }
    }
}
