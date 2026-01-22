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
                    'auto_reply_message' => 'Hvala na poruci! Odgovorit ću vam u najkraćem mogućem roku.',
                    'vacation_mode' => false,
                    'vacation_message' => 'Trenutno sam na odmoru. Vratit ću se uskoro!',

                    // ove tekstualne/socijalne ostavi null po defaultu (frontend će prikazati "")
                    'business_description' => null,
                    'return_policy' => null,
                    'shipping_info' => null,

                    'social_facebook' => null,
                    'social_instagram' => null,
                    'social_tiktok' => null,
                    'social_youtube' => null,
                    'social_website' => null,

                    'business_hours' => null,
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
            /**
             * ✅ FULL FIX:
             * - Konvertuje "" -> null za nullable polja (da nullable|url NE puca)
             * - Dodaje https:// za social url ako nema scheme (opciono ali korisno)
             * - Normalizuje business_hours da bude array ili null
             * - Validacija sa sometimes za boolean (da ne zavisiš od slanja svih polja)
             */

            // 1) "" -> null (za polja gdje je prazno realno "nema vrijednosti")
            $nullableStringFields = [
                'whatsapp_number',
                'viber_number',
                'preferred_contact_method',

                'auto_reply_message',
                'vacation_message',

                'business_description',
                'return_policy',
                'shipping_info',

                'social_facebook',
                'social_instagram',
                'social_tiktok',
                'social_youtube',
                'social_website',
            ];

            $merged = [];
            foreach ($nullableStringFields as $f) {
                if ($request->has($f)) {
                    $v = $request->input($f);

                    if (is_string($v)) {
                        $v = trim($v);
                        $merged[$f] = ($v === '') ? null : $v;
                    }
                }
            }

            // 2) Ako social url nema scheme, dodaj https:// (da "facebook.com/..." prođe url rule)
            $socialFields = ['social_facebook','social_instagram','social_tiktok','social_youtube','social_website'];
            foreach ($socialFields as $f) {
                if (array_key_exists($f, $merged) && is_string($merged[$f]) && $merged[$f] !== null) {
                    if (!preg_match('~^https?://~i', $merged[$f])) {
                        $merged[$f] = 'https://' . $merged[$f];
                    }
                }
            }

            if (!empty($merged)) {
                $request->merge($merged);
            }

            // 3) business_hours normalizacija (string JSON -> array, ostalo -> null)
            if ($request->has('business_hours')) {
                $bh = $request->input('business_hours');

                if (is_string($bh)) {
                    $decoded = json_decode($bh, true);
                    $bh = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
                }

                $request->merge([
                    'business_hours' => is_array($bh) ? $bh : null
                ]);
            }

            // 4) Validacija
            $validator = Validator::make($request->all(), [
                'avatar_id' => ['nullable', 'string', 'max:50', Rule::in(self::AVATAR_IDS)],

                'show_phone' => 'sometimes|boolean',
                'show_email' => 'sometimes|boolean',
                'show_whatsapp' => 'sometimes|boolean',
                'show_viber' => 'sometimes|boolean',

                'whatsapp_number' => 'nullable|string|max:20',
                'viber_number' => 'nullable|string|max:20',
                'preferred_contact_method' => 'nullable|in:message,phone,whatsapp,viber,email',

                'business_hours' => 'nullable|array',
                'response_time' => 'nullable|in:auto,instant,few_hours,same_day,few_days',
                'accepts_offers' => 'sometimes|boolean',

                'auto_reply_enabled' => 'sometimes|boolean',
                'auto_reply_message' => 'nullable|string|max:300',

                'vacation_mode' => 'sometimes|boolean',
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
                    'message' => $validator->errors()->first(),
                    // korisno za debug (možeš maknuti kasnije)
                    'errors' => $validator->errors(),
                ], 422);
            }

            // 5) Upsert settings
            $user = Auth::user();
            $settings = SellerSetting::firstOrCreate(
                ['user_id' => $user->id],
                ['avatar_id' => self::AVATAR_IDS[0]]
            );

            // 6) Spremi samo whitelist polja
            $updateData = $request->only([
                'avatar_id',

                'show_phone',
                'show_email',
                'show_whatsapp',
                'show_viber',
                'whatsapp_number',
                'viber_number',
                'preferred_contact_method',

                'business_hours',
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

            // 7) Avatar fallback
            if (array_key_exists('avatar_id', $updateData) && empty($updateData['avatar_id'])) {
                $updateData['avatar_id'] = self::AVATAR_IDS[0];
            }

            // 8) Update
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
