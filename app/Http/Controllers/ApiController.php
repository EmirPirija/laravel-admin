<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Api\Concerns\HandlesAuthIdentity;
use App\Http\Resources\ItemCollection;
use App\Models\Area;
use App\Events\UserRealtimeNotification;
use App\Models\SellerSetting;
use App\Jobs\NotifyFollowersInstant;
use App\Models\UserMembership;
use App\Models\BlockUser;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Chat;
use App\Models\City;
use App\Models\BihMunicipality;
use App\Models\ContactUs;
use App\Models\Country;
use App\Models\CustomField;
use App\Models\Faq;
use App\Models\Favourite;
use App\Models\FeaturedItems;
use App\Models\FeatureSection;
use App\Models\Item;
use App\Models\ItemCustomFieldValue;
use App\Models\ItemImages;
use App\Models\ItemOffer;
use App\Models\JobApplication;
use App\Models\Language;
use App\Models\Notifications;
use App\Models\NumberOtp;
use App\Models\Package;
use App\Models\PaymentConfiguration;
use App\Models\PaymentTransaction;
use App\Models\ReportReason;
use App\Models\SellerRating;
use App\Models\SeoSetting;
use App\Models\Setting;
use App\Models\Slider;
use App\Models\SocialLogin;
use App\Models\State;
use App\Models\Tip;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Models\UserPurchasedPackage;
use App\Models\UserReports;
use App\Models\VerificationField;
use App\Models\VerificationFieldValue;
use App\Models\VerificationRequest;
use App\Models\TempMedia;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\AuthEventService;
use App\Services\FeaturedAdService;
use App\Services\HelperService;
use App\Services\ListingCampaignBadgeService;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use App\Services\ResponseService;
use App\Services\UserDeletionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;
use Twilio\Rest\Client as TwilioRestClient;

use Illuminate\Support\Facades\Cache;


use Illuminate\Support\Str;
class ApiController extends Controller
{
    use HandlesAuthIdentity;

    private string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'item_images';
        if (array_key_exists('HTTP_AUTHORIZATION', $_SERVER) && ! empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $this->middleware('auth:sanctum');
        }
    }

protected static function booted()
{
    static::saved(fn() => Cache::flush());   // brzo rješenje
    static::deleted(fn() => Cache::flush());
}

    public function getSystemSettings(Request $request)
    {
        try {
            $query = Setting::select(['id', 'name', 'value', 'type']); // include 'id' to support translation loading

            if (! empty($request->type)) {
                $query->where('name', $request->type);
            }

            $settings = $query->with('translations')->get();

            $tempRow = [];

            foreach ($settings as $row) {
                if (in_array($row->name, [
                    'account_holder_name',
                    'bank_name',
                    'account_number',
                    'ifsc_swift_code',
                    'bank_transfer_status',
                    'place_api_key',
                ])) {
                    continue;
                }
                $tempRow[$row->name] = $row->translated_value ?? $row->value;
            }

            // --- determine current language ---
            $languageCode = $request->header('Content-Language') ?? app()->getLocale();
            $language = Language::where('code', $languageCode)->first();

            if (! $language) {
                $defaultLanguageCode = Setting::where('name', 'default_language')->value('value');
                $language = Language::where('code', $defaultLanguageCode)->first();
            }

            $tempRow['demo_mode'] = config('app.demo_mode');
            $tempRow['languages'] = CachingService::getLanguages();
            $tempRow['admin'] = User::role('Super Admin')->select(['name', 'profile'])->first();

            // 👇 add current language info
            $tempRow['current_language'] = $language?->code ?? app()->getLocale();

            ResponseService::successResponse(__('Data Fetched Successfully'), $tempRow);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getSystemSettings');
            ResponseService::errorResponse();
        }
    }

    public function userSignup(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:email,google,phone,apple',
                'firebase_id' => 'required',
                'email' => 'required_if:type,email,google,apple|nullable|email:rfc,dns|max:191',
                'mobile' => 'required_if:type,phone|string|max:32',
                'country_code' => 'nullable|string|max:8',
                'auth_intent' => 'nullable|in:login,register',
                'flag' => 'boolean',
                'platform_type' => 'nullable|in:android,ios',
                'region_code'  => 'nullable|string|max:8',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $type = $request->type;
            $firebase_id = $request->firebase_id;
            $authIntent = $request->input('auth_intent', 'login');
            $phoneRegisterFallbackToLogin = false;
            $normalizedEmail = $this->normalizeEmail($request->email);
            $phoneInput = $this->normalizePhoneInput($request->country_code, $request->mobile);
            $eventIdentifier = $type === 'phone'
                ? $phoneInput['full']
                : (string) ($normalizedEmail ?: $firebase_id);
            AuthEventService::log('signup_attempt', [
                'type' => $type,
                'intent' => $authIntent,
            ], 'info', $eventIdentifier);

            if ($type === 'phone' && $phoneInput['full'] === '') {
                ResponseService::validationError(__('Unesite ispravan broj telefona.'));
            }

            $socialLogin = SocialLogin::where('firebase_id', $firebase_id)
                ->where('type', $type)
                ->with('user', function ($q) {
                    $q->withTrashed();
                })
                ->whereHas('user', function ($q) {
                    $q->role('User');
                })
                ->first();

            if (! empty($socialLogin->user->deleted_at)) {
                ResponseService::errorResponse(
                    __('User is deactivated. Please Contact the administrator'),
                    null,
                    config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT'),
                    null,
                    403
                );
            }

            if ($type === 'phone' && $authIntent === 'register' && ! empty($socialLogin)) {
                $phoneRegisterFallbackToLogin = true;
            }

            if (empty($socialLogin)) {
                if ($type === 'phone') {
                    $request->merge([
                        'mobile' => $phoneInput['mobile'],
                        'country_code' => $phoneInput['country'] ?: null,
                    ]);

                    $existingPhoneUser = $this->findPhoneConflict(
                        $phoneInput['country'],
                        $phoneInput['mobile'],
                        null,
                        false,
                        true
                    );

                    if ($existingPhoneUser && ! $existingPhoneUser->hasRole('User')) {
                        ResponseService::errorResponse(
                            __('Invalid Login Credentials'),
                            null,
                            config('constants.RESPONSE_CODE.INVALID_LOGIN'),
                            null,
                            403
                        );
                    }

                    if ($existingPhoneUser && $existingPhoneUser->trashed()) {
                        ResponseService::errorResponse(
                            __('Your account has been deactivated.'),
                            null,
                            config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT'),
                            null,
                            403
                        );
                    }

                    if ($existingPhoneUser && $authIntent === 'register') {
                        $phoneRegisterFallbackToLogin = true;
                    }

                    if (! $existingPhoneUser && $authIntent === 'login') {
                        $this->phoneNotRegisteredResponse();
                    }

                    DB::beginTransaction();
                    if ($existingPhoneUser) {
                        $user = $existingPhoneUser;
                    } else {
                        $user = User::create([
                            ...$request->all(),
                            'mobile' => $phoneInput['mobile'],
                            'country_code' => $phoneInput['country'] ?: null,
                            'region_code' => $request->region_code ?? null,
                            'phone_verified_at' => now(),
                            'profile' => $request->hasFile('profile')
                                ? $request->file('profile')->store('user_profile', 'public')
                                : $request->profile,
                        ]);
                        $user->assignRole('User');
                    }
                } else {
                    if ($normalizedEmail === '') {
                        ResponseService::validationError(__('Unesite ispravan e-mail.'));
                    }

                    $request->merge(['email' => $normalizedEmail]);
                    $existingUser = User::withTrashed()
                        ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                        ->first();

                    if ($existingUser && $existingUser->trashed()) {
                        ResponseService::errorResponse(
                            __('Your account has been deactivated.'),
                            null,
                            config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT'),
                            null,
                            403
                        );
                    }

                    if ($existingUser && ! $existingUser->hasRole('User')) {
                        ResponseService::errorResponse(
                            __('Invalid Login Credentials'),
                            null,
                            config('constants.RESPONSE_CODE.INVALID_LOGIN'),
                            null,
                            403
                        );
                    }

                    $firebaseTypeConflict = SocialLogin::query()
                        ->where('type', $type)
                        ->where('firebase_id', $firebase_id)
                        ->when($existingUser, fn($q) => $q->where('user_id', '!=', $existingUser->id))
                        ->first();

                    if ($firebaseTypeConflict) {
                        ResponseService::conflictResponse(
                            __('Ovaj nalog je već povezan sa drugim korisnikom.'),
                            ['reason' => 'account_already_linked']
                        );
                    }

                    DB::beginTransaction();
                    $payload = [
                        ...$request->all(),
                        'email' => $normalizedEmail,
                        'region_code' => $request->region_code ?? null,
                        'profile' => $request->hasFile('profile')
                            ? $request->file('profile')->store('user_profile', 'public')
                            : $request->profile,
                    ];

                    if (empty($payload['password'])) {
                        $payload['password'] = Hash::make(Str::random(40));
                    }

                    if ($existingUser) {
                        $existingUser->fill($payload);
                        $existingUser->save();
                        $user = $existingUser;
                    } else {
                        $user = User::create($payload);
                    }

                    if (! $user->hasRole('User')) {
                        $user->assignRole('User');
                    }
                }

                SocialLogin::updateOrCreate([
                    'type' => $type,
                    'user_id' => $user->id,
                ], [
                    'firebase_id' => $firebase_id,
                ]);

                Auth::login($user);
                $auth = User::find($user->id);
                DB::commit();
            } else {
                Auth::login($socialLogin->user);
                $auth = Auth::user();
            }

            if (! $auth->hasRole('User')) {
                ResponseService::errorResponse(
                    __('Invalid Login Credentials'),
                    null,
                    config('constants.RESPONSE_CODE.INVALID_LOGIN'),
                    null,
                    403
                );
            }

            if (! empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(
                    ['fcm_token' => $request->fcm_id],
                    [
                        'user_id' => $auth->id,
                        'platform_type' => $request->platform_type,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );
            }

            $auth->fcm_id = $request->fcm_id;
            if (!empty($request->registration)) {
                $token = null;
            } else {
                $token = $auth->createToken($auth->name ?? '')->plainTextToken;
                $this->persistTokenSessionMetadata($token, $request, $request->platform_type);
            }

            if ($auth && !empty($auth->email) && filter_var($auth->email, FILTER_VALIDATE_EMAIL)) {
                NotificationService::sendNewDeviceLoginEmail($auth, $request);
            }
            AuthEventService::log('signup_success', [
                'type' => $type,
                'intent' => $authIntent,
                'user_id' => $auth->id ?? null,
                'register_fallback_login' => $phoneRegisterFallbackToLogin,
            ], 'success', $eventIdentifier, $auth->id ?? null);

            $successMessage = ($type === 'phone' && $phoneRegisterFallbackToLogin)
                ? __('Broj je već registrovan. Prijavili smo vas na postojeći račun.')
                : __('User logged-in successfully');

            ResponseService::successResponse($successMessage, $auth, [
                'token' => $token,
                'meta' => [
                    'register_fallback_login' => $phoneRegisterFallbackToLogin,
                ],
            ]);
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->isUniqueConstraintViolation($th)) {
                $this->throwIdentityConflictFromException($th);
            }
            AuthEventService::log('signup_failed', [
                'error' => $th->getMessage(),
            ], 'error', (string) ($request->input('email') ?? $request->input('mobile') ?? $request->input('firebase_id')));
            ResponseService::logErrorResponse($th, 'API Controller -> Signup');
            ResponseService::errorResponse();
        }
    }

    public function resolveLoginIdentifier(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|min:3|max:191',
                'identifier_type' => 'nullable|in:auto,email_username,phone',
                'country_code' => 'nullable|string|max:8',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $identifier = trim((string) $request->input('identifier', ''));
            if ($identifier === '') {
                ResponseService::validationError(__('Invalid Login Credentials'));
            }
            AuthEventService::log('login_identifier_attempt', [
                'identifier_type' => $request->input('identifier_type', 'auto'),
            ], 'info', $identifier);

            $identifierType = (string) $request->input('identifier_type', 'auto');
            if ($identifierType === 'phone') {
                $phone = $this->normalizePhoneInput($request->input('country_code'), $identifier);
                if ($phone['full'] === '') {
                    ResponseService::validationError(__('Unesite ispravan broj telefona.'));
                }

                $user = $this->findPhoneConflict(
                    $phone['country'],
                    $phone['mobile'],
                    null,
                    false,
                    true
                );

                if (! $user) {
                    $this->phoneNotRegisteredResponse();
                }

                if (! $user->hasRole('User')) {
                    ResponseService::errorResponse(
                        __('Invalid Login Credentials'),
                        null,
                        config('constants.RESPONSE_CODE.VALIDATION_ERROR'),
                        null,
                        403
                    );
                }

                if (! empty($user->deleted_at)) {
                    ResponseService::errorResponse(
                        __('Your account has been deactivated.'),
                        null,
                        config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT'),
                        null,
                        403
                    );
                }
                AuthEventService::log('login_identifier_resolved', [
                    'identifier_type' => 'phone',
                    'user_id' => $user->id,
                ], 'success', $phone['full'], $user->id);

                ResponseService::successResponse(__('Data Fetched Successfully'), [
                    'user_id' => $user->id,
                    'identifier_type' => 'phone',
                    'country_code' => $phone['country'] ?: null,
                    'mobile' => $phone['mobile'],
                    'phone' => $phone['full'],
                ]);
            }

            $identifierLower = Str::lower($identifier);
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
            static $hasUsernameColumn = null;
            if ($hasUsernameColumn === null) {
                $hasUsernameColumn = Schema::hasColumn('users', 'username');
            }

            $query = User::query()
                ->role('User')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->select(['id', 'name', 'email']);

            if ($isEmail) {
                $query->whereRaw('LOWER(email) = ?', [$identifierLower]);
            } else {
                $query->where(function ($q) use ($identifierLower, $hasUsernameColumn) {
                    if ($hasUsernameColumn) {
                        $q->orWhereRaw('LOWER(username) = ?', [$identifierLower]);
                    }

                    // In existing flows "username" is often persisted into the "name" column.
                    $q->orWhereRaw('LOWER(name) = ?', [$identifierLower]);
                });

                if ($hasUsernameColumn) {
                    $query->orderByRaw('CASE WHEN LOWER(username) = ? THEN 0 ELSE 1 END', [$identifierLower]);
                }
            }

            $user = $query->first();

            if (! $user || empty($user->email)) {
                ResponseService::errorResponse(
                    __('Invalid Login Credentials'),
                    null,
                    config('constants.RESPONSE_CODE.NOT_FOUND'),
                    null,
                    404
                );
            }
            AuthEventService::log('login_identifier_resolved', [
                'identifier_type' => $isEmail ? 'email' : 'username',
                'user_id' => $user->id,
            ], 'success', $identifier, $user->id);

            ResponseService::successResponse(__('Data Fetched Successfully'), [
                'user_id' => $user->id,
                'email' => $user->email,
                'identifier_type' => $isEmail ? 'email' : 'username',
            ]);
        } catch (Throwable $th) {
            AuthEventService::log('login_identifier_failed', [
                'error' => $th->getMessage(),
            ], 'error', (string) $request->input('identifier'));
            ResponseService::logErrorResponse($th, 'API Controller -> resolveLoginIdentifier');
            ResponseService::errorResponse();
        }
    }

    public function getUser(Request $request)
    {
        try {
            $auth = Auth::user();

            if (! $auth) {
                ResponseService::errorResponse(__('User not authenticated'));
            }

            if (! $auth->hasRole('User')) {
                ResponseService::errorResponse(__('Invalid User Role'));
            }

            // Fetch latest user details from DB
            $user = User::find($auth->id);

            ResponseService::successResponse(__('User fetched successfully'), $user);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> GetUser');
            ResponseService::errorResponse();
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'                  => 'nullable|string',
                'profile'               => 'nullable|mimes:jpg,jpeg,png|max:7168',
                'email'                 => 'nullable|email|unique:users,email,' . Auth::user()->id,
                'mobile'                => 'nullable|string|max:32',
                'fcm_id'                => 'nullable',
                'address'               => 'nullable',
                'show_personal_details' => 'boolean',
                'country_code' => 'nullable|string|max:8',
                'region_code' =>  'nullable|string|max:8',
                'mark_phone_verified' => 'nullable|boolean',
                'use_svg_avatar' => 'nullable|boolean',
                'avatar_key' => ['nullable','string','max:50', Rule::in([
                'lmx-01','lmx-02','lmx-03','lmx-04','lmx-05','lmx-06',
                'lmx-07','lmx-08','lmx-09','lmx-10','lmx-11','lmx-12',
                ])],
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $app_user = Auth::user();
            //Email should not be updated when type is google.
            $data = $app_user->type == 'google' ? $request->except('email') : $request->all();
            unset($data['mark_phone_verified']);

            $incomingPhoneProvided = $request->filled('mobile') || $request->filled('country_code');
            if ($incomingPhoneProvided) {
                $incomingPhone = $this->normalizePhoneInput(
                    $request->input('country_code', $app_user->country_code),
                    $request->input('mobile', $app_user->mobile)
                );

                if ($incomingPhone['mobile'] === '') {
                    ResponseService::validationError(__('Unesite ispravan broj telefona.'));
                }

                $isPhoneVerificationRequest = $request->boolean('mark_phone_verified');
                if ($isPhoneVerificationRequest) {
                    $conflict = $this->findPhoneConflict(
                        $incomingPhone['country'],
                        $incomingPhone['mobile'],
                        $app_user->id,
                        true,
                        false
                    );

                    if (! empty($conflict)) {
                        ResponseService::conflictResponse(
                            __('Ovaj broj je već verificiran na drugom računu.'),
                            ['reason' => 'phone_already_verified_elsewhere'],
                            config('constants.RESPONSE_CODE.CONFLICT')
                        );
                    }
                }

                $currentPhone = $this->normalizePhoneInput($app_user->country_code, $app_user->mobile);
                $phoneChanged = $currentPhone['full'] !== $incomingPhone['full'];

                $data['mobile'] = $incomingPhone['mobile'];
                $data['country_code'] = $incomingPhone['country'] ?: null;

                if ($isPhoneVerificationRequest) {
                    $data['phone_verified_at'] = now();
                } elseif ($phoneChanged) {
                    $data['phone_verified_at'] = null;
                }
            }

            if ($request->hasFile('profile')) {
                $data['profile'] = FileService::compressAndReplace($request->file('profile'), 'profile', $app_user->getRawOriginal('profile'));
                // ako user uploaduje sliku, logično je da SVG avatar nije aktivan
                $data['use_svg_avatar'] = false;
            }

            if (! empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], ['user_id' => $app_user->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            }
            $data['show_personal_details'] = $request->show_personal_details;

            $app_user->update($data);
            ResponseService::successResponse(__('Profile Updated Successfully'), $app_user);
        } catch (Throwable $th) {
            if ($this->isUniqueConstraintViolation($th)) {
                $this->throwIdentityConflictFromException($th);
            }
            ResponseService::logErrorResponse($th, 'API Controller -> updateProfile');
            ResponseService::errorResponse();
        }
    }

    public function getPackage(Request $request)
    {
        $validator = Validator::make($request->toArray(), [
            'platform' => 'nullable|in:android,ios',
            'type' => 'nullable|in:advertisement,item_listing',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $packages = Package::with('translations')->where('status', 1);

            if (Auth::check()) {
                $packages = $packages->with('user_purchased_packages', function ($q) {
                    $q->onlyActive();
                });
            }

            if (isset($request->platform) && $request->platform == 'ios') {
                $packages->whereNotNull('ios_product_id');
            }

            if (! empty($request->type)) {
                $packages = $packages->where('type', $request->type);
            }
            $packages = $packages->orderBy('id', 'ASC')->get();

            $packages->map(function ($package) {
                if (Auth::check()) {
                    $package['is_active'] = count($package->user_purchased_packages) > 0;
                } else {
                    $package['is_active'] = false;
                }

                return $package;
            });
            ResponseService::successResponse(__('Data Fetched Successfully'), $packages);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getPackage');
            ResponseService::errorResponse();
        }
    }

    public function assignFreePackage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'package_id' => 'required|exists:packages,id',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            $package = Package::where(['final_price' => 0, 'id' => $request->package_id])->firstOrFail();
            $activePackage = UserPurchasedPackage::where(['package_id' => $request->package_id, 'user_id' => Auth::user()->id])->first();
            if (! empty($activePackage)) {
                ResponseService::errorResponse(__('You already have purchased this package'));
            }

            UserPurchasedPackage::create([
                'user_id' => $user->id,
                'package_id' => $request->package_id,
                'start_date' => Carbon::now(),
                'total_limit' => $package->item_limit == 'unlimited' ? null : $package->item_limit,
                'end_date' => $package->duration == 'unlimited' ? null : Carbon::now()->addDays($package->duration),
            ]);
            ResponseService::successResponse(__('Package Purchased Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> assignFreePackage');
            ResponseService::errorResponse();
        }
    }

    public function getLimits(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'package_type' => 'required|in:item_listing,advertisement',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $setting = Setting::where('name', 'free_ad_listing')->first()['value'];
            if ($setting == 1 && $request->package_type != 'advertisement') {
                return ResponseService::successResponse(__('User is allowed to create Advertisement'));
            }
            $user_package = UserPurchasedPackage::onlyActive()->whereHas('package', function ($q) use ($request) {
                $q->where('type', $request->package_type);
            })->count();
            if ($user_package > 0) {
                ResponseService::successResponse(__('User is allowed to create Advertisement'));
            }
            ResponseService::errorResponse(__('User is not allowed to create Advertisement'), $user_package);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getLimits');
            ResponseService::errorResponse();
        }
    }

    public function addItem(Request $request)
    {
    try {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'category_id' => 'required|integer',
            'description' => 'required',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'address' => 'required',
            'contact' => 'numeric',
            'show_only_to_premium' => 'nullable|boolean',
            'video_link' => 'nullable|url',
            'gallery_images' => 'nullable|array|min:1',
            'gallery_images.*' => 'nullable|mimes:jpeg,png,jpg|max:7168',
            'image' => 'nullable|mimes:jpeg,png,jpg|max:7168',
            'temp_main_image_id' => 'nullable|integer|exists:temp_media,id',
            'temp_gallery_image_ids' => 'nullable|array',
            'temp_gallery_image_ids.*' => 'integer|exists:temp_media,id',
            'temp_video_id' => 'nullable|integer|exists:temp_media,id',
            'country' => 'required',
            'state' => 'nullable',
            'city' => 'required',
            'custom_field_files' => 'nullable|array',
            'video' => 'nullable|mimetypes:video/mp4,video/quicktime,video/webm|max:51200',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'slug' => [
                'nullable',
                'regex:/^(?!-)(?!.*--)(?!.*-$)(?!-$)[a-z0-9-]+$/',
            ],
            'region_code' => 'nullable|string',
            'show_only_to_premium' => 'nullable|boolean',
            'available_now' => 'nullable|boolean',
            'is_available' => 'nullable|boolean',
            'is_avaible' => 'nullable|boolean',
            'isAvailable' => 'nullable|boolean',
            'exchange_possible' => 'nullable|boolean',
            'is_exchange' => 'nullable|boolean',
            'is_exchange_possible' => 'nullable|boolean',
            'allow_exchange' => 'nullable|boolean',
            'exchange' => 'nullable|boolean',
            'zamjena' => 'nullable|boolean',
            'zamena' => 'nullable|boolean',
            'scarcity_enabled' => 'nullable|boolean',
            'is_scarcity_enabled' => 'nullable|boolean',
            'publish_to_instagram' => 'nullable|boolean',
            'add_video_to_story' => 'nullable|boolean',
            'instagram_source_url' => 'nullable|url|max:1000',
            'price_per_unit' => 'nullable|numeric|min:0',
            'minimum_order_quantity' => 'nullable|integer|min:1|max:100000',
            'stock_alert_threshold' => 'nullable|integer|min:1|max:1000',
            'seller_product_code' => 'nullable|string|max:100',
            'campaign_badge_key' => 'nullable|string|max:80',
        ]);

        // Zakazana objava - MORA biti prije kreiranje itema
        if ($request->has('scheduled_at') && !empty($request->scheduled_at)) {
            $data['scheduled_at'] = $request->scheduled_at;
            $data['status'] = 'scheduled';
        }

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        if (
            $this->normalizeLocationSourceValue($request->input('location_source')) === 'map' &&
            !$this->requestHasAnyValidCoordinatePair($request)
        ) {
            ResponseService::validationError('Za odabrani način "Tačan pin na mapi" označite pin na mapi.');
        }
        $resolvedCoords = $this->resolveListingCoordinates($request);
        $request->merge([
            'latitude' => $resolvedCoords['lat'],
            'longitude' => $resolvedCoords['lng'],
        ]);

        // 🔹 Validacija translations
        $translations = $this->decodeJsonArrayInput($request, 'translations', []);
        if (!empty($translations)) {
            foreach ($translations as $languageId => $translation) {
                Validator::make($translation, [
                    'name' => 'required|string|max:255',
                    'slug' => 'nullable|regex:/^[a-z0-9-]+$/',
                    'description' => 'nullable|string',
                    'address' => 'nullable|string',
                    'video_link' => 'nullable|url',
                    'rejected_reason' => 'nullable|string',
                    'admin_edit_reason' => 'nullable|string',
                ])->validate();
            }
        }

        $category = Category::findOrFail($request->category_id);

        $isJobCategory = $category->is_job_category;
        $isPriceOptional = $category->price_optional;

        // 🔹 Validacija cijene / plate
        if ($isJobCategory || $isPriceOptional) {
            $priceValidator = Validator::make($request->all(), [
                'min_salary' => 'nullable|numeric|min:0',
                'max_salary' => 'nullable|numeric|gte:min_salary',
            ]);
        } else {
            $priceValidator = Validator::make($request->all(), [
                'price' => 'required|numeric|min:0',
            ]);
        }

        if ($priceValidator->fails()) {
            ResponseService::validationError($priceValidator->errors()->first());
        }

        DB::beginTransaction();

        $user = Auth::user();

        $user_package = UserPurchasedPackage::onlyActive()
            ->whereHas('package', static function ($q) {
                $q->where('type', 'item_listing');
            })
            ->where('user_id', $user->id)
            ->first();

        $free_ad_listing = Setting::where('name', 'free_ad_listing')->value('value') ?? 0;
        $auto_approve_item = Setting::where('name', 'auto_approve_item')->value('value') ?? 0;

        $status = ($auto_approve_item == 1 || $user->auto_approve_item == 1)
            ? 'approved'
            : 'approved'; // ako želiš pending, ovdje promijeni

        if ($free_ad_listing == 0 && empty($user_package)) {
            ResponseService::errorResponse(__('No Active Package found for Advertisement Creation'));
        }

        if ($user_package) {
            $user_package->used_limit++;
            $user_package->save();
        }

        // 🔹 SLUG
        $slug = trim($request->input('slug') ?? '');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug));
        $slug = trim($slug, '-');
        if (empty($slug)) {
            $slug = HelperService::generateRandomSlug();
        }
        $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug);

        // 🔹 Priprema $data
        $data = $request->all(); // bazni podaci iz requesta
        $storyColumnExists = Schema::hasColumn('items', 'add_video_to_story');
        if (!$storyColumnExists) {
            unset($data['add_video_to_story']);
        }

        $data['name']        = $request->name;
        $data['slug']        = $uniqueSlug;
        $data['status']      = $status;
        $data['active']      = 'deactive';
        $data['user_id']     = $user->id;
        $data['package_id']  = $user_package->package_id ?? null;
        $data['expiry_date'] = $user_package->end_date ?? null;

        // Podrška za zakazanu objavu
        if ($request->filled('scheduled_at')) {
            $data['scheduled_at'] = Carbon::parse($request->scheduled_at)->utc();
            $data['status'] = 'scheduled';
            $data['active'] = 'deactive';
        } else {
            $data['scheduled_at'] = null;
            $data['status'] = $status;
        }
        
        

        // 🔹 Akcija/Sale polja
        $data['is_on_sale'] = filter_var($request->input('is_on_sale', false), FILTER_VALIDATE_BOOLEAN);
        $data['old_price']  = $data['is_on_sale'] && $request->filled('old_price')
            ? $request->input('old_price')
            : null;
        $data['price_history'] = json_encode([]);
        $availableNowInput = $request->input(
            'available_now',
            $request->input(
                'is_available',
                $request->input('is_avaible', $request->input('isAvailable'))
            )
        );
        $data['available_now'] = filter_var($availableNowInput ?? false, FILTER_VALIDATE_BOOLEAN);

        $exchangeInput = $request->input(
            'exchange_possible',
            $request->input(
                'is_exchange',
                $request->input(
                    'is_exchange_possible',
                    $request->input(
                        'allow_exchange',
                        $request->input(
                            'exchange',
                            $request->input('zamjena', $request->input('zamena'))
                        )
                    )
                )
            )
        );
        $data['exchange_possible'] = filter_var($exchangeInput ?? false, FILTER_VALIDATE_BOOLEAN);
        $scarcityInput = $request->input('scarcity_enabled', $request->input('is_scarcity_enabled'));
        $data['scarcity_enabled'] = filter_var($scarcityInput ?? false, FILTER_VALIDATE_BOOLEAN);

        $data['publish_to_instagram'] = filter_var(
            $request->input('publish_to_instagram', false),
            FILTER_VALIDATE_BOOLEAN
        );
        $storyRequested = filter_var(
            $request->input('add_video_to_story', false),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($storyColumnExists) {
            $data['add_video_to_story'] = false;
        }
        $data['instagram_source_url'] = $request->filled('instagram_source_url')
            ? trim((string) $request->input('instagram_source_url'))
            : null;

        $data['price_per_unit'] = $request->filled('price_per_unit')
            ? (float) $request->input('price_per_unit')
            : null;
        $data['minimum_order_quantity'] = $request->filled('minimum_order_quantity')
            ? (int) $request->input('minimum_order_quantity')
            : 1;
        $data['stock_alert_threshold'] = $request->filled('stock_alert_threshold')
            ? (int) $request->input('stock_alert_threshold')
            : null;
        $data['seller_product_code'] = $request->filled('seller_product_code')
            ? trim((string) $request->input('seller_product_code'))
            : null;
        $campaignBadgeOption = $this->resolveCampaignBadgeOption(
            $request->input('campaign_badge_key')
        );
        $data['campaign_badge_key'] = $campaignBadgeOption['key'] ?? null;
        $data['campaign_badge_label'] = $campaignBadgeOption['label'] ?? null;

        // 🔹 Glavna slika (podržava upload file ILI temp upload)
        $tempMainImageId = $request->input('temp_main_image_id');
        $tempImageIds = $request->input('temp_image_ids'); // fallback (ako šalješ sve slike u jednom nizu)
        $tempVideoId = $request->input('temp_video_id');

        if (is_string($tempImageIds)) {
            $tempImageIds = array_values(array_filter(explode(',', $tempImageIds)));
        }

        if ($request->hasFile('image')) {
            $data['image'] = FileService::compressAndUploadWithWatermark(
                $request->file('image'),
                $this->uploadFolder
            );
        } elseif (!empty($tempMainImageId)) {
            $temp = TempMedia::where('id', $tempMainImageId)->where('type', 'image')->first();
            if ($temp) {
                $data['image'] = $this->moveTempMediaToPermanent($temp->path, $this->uploadFolder);
                $temp->delete();
            }
        } elseif (is_array($tempImageIds) && count($tempImageIds) > 0) {
            // ako nije eksplicitno poslan main, uzmi prvi kao main
            $temp = TempMedia::where('id', $tempImageIds[0])->where('type', 'image')->first();
            if ($temp) {
                $data['image'] = $this->moveTempMediaToPermanent($temp->path, $this->uploadFolder);
                $temp->delete();
            }
        }

        // 🎬 Video upload (podržava upload file ILI temp upload)
        if ($request->hasFile('video')) {
            $data['video'] = FileService::upload(
                $request->file('video'),
                'item_videos'
            );
        } elseif (!empty($tempVideoId)) {
            $tempV = TempMedia::where('id', $tempVideoId)->where('type', 'video')->first();
            if ($tempV) {
                $data['video'] = $this->moveTempMediaToPermanent($tempV->path, 'item_videos');
                $tempV->delete();
            }
        }

        if ($storyColumnExists) {
            $hasVideoForStory = !empty($data['video']) || $request->filled('video_link');
            if ($storyRequested && !$hasVideoForStory) {
                ResponseService::validationError('Dodajte video ili video URL prije uključivanja story objave.');
            }
            if ($storyRequested) {
                $this->ensureStorySlotAvailable((int) $user->id);
            }
            $data['add_video_to_story'] = $storyRequested && $hasVideoForStory;
        }

        // 🔹 Kreiranje item-a
        $item = Item::create($data);

        if ($request->has('inventory_count') && $request->inventory_count !== null) {
            $item->inventory_count = (int) $request->inventory_count;
        }
        if ($item->inventory_count !== null && (int) $item->inventory_count <= 0) {
            $continueSelling = (bool) optional($user->sellerSettings)->continue_selling_out_of_stock;
            if (! $continueSelling) {
                $item->status = 'sold out';
            }
        }
        if ($item->inventory_count !== null && (int) $item->inventory_count > 0 && $item->status === 'sold out') {
            $item->status = 'approved';
        }
        $item->save();
        

        // 🔹 Translations za item
        if (!empty($translations)) {
            foreach ($translations as $languageId => $translationData) {
                if (Language::where('id', $languageId)->exists()) {
                    $item->translations()->create([
                        'language_id'      => $languageId,
                        'name'             => $translationData['name'],
                        'description'      => $translationData['description'] ?? '',
                        'address'          => $translationData['address'] ?? '',
                        'rejected_reason'  => $translationData['rejected_reason'] ?? null,
                        'admin_edit_reason'=> $translationData['admin_edit_reason'] ?? null,
                    ]);
                }
            }
        }

        // 🔹 Gallery slike (upload file ILI temp IDs)
        $tempGalleryIds = $request->input('temp_gallery_image_ids');
        $tempImageIds = $request->input('temp_image_ids'); // fallback: ako šalješ sve slike
        if (is_string($tempGalleryIds)) {
            $tempGalleryIds = array_values(array_filter(explode(',', $tempGalleryIds)));
        }
        if (is_string($tempImageIds)) {
            $tempImageIds = array_values(array_filter(explode(',', $tempImageIds)));
        }

        $galleryImages = [];

        // 1) temp galerija (ako je poslana)
        $idsToUse = [];
        if (is_array($tempGalleryIds) && count($tempGalleryIds) > 0) {
            $idsToUse = $tempGalleryIds;
        } elseif (is_array($tempImageIds) && count($tempImageIds) > 1) {
            // ako je prvi bio main, ostalo je galerija
            $idsToUse = array_slice($tempImageIds, 1);
        }

        if (count($idsToUse) > 0) {
            $temps = TempMedia::whereIn('id', $idsToUse)->where('type', 'image')->get()->keyBy('id');
            foreach ($idsToUse as $id) {
                $t = $temps->get($id);
                if (!$t) continue;
                $newPath = $this->moveTempMediaToPermanent($t->path, $this->uploadFolder);
                $galleryImages[] = [
                    'image'     => $newPath,
                    'item_id'   => $item->id,
                    'created_at'=> time(),
                    'updated_at'=> time(),
                ];
                $t->delete();
            }
        }

        // 2) klasični upload (backward compatibility)
        if ($request->hasFile('gallery_images')) {
            foreach ($request->file('gallery_images') as $file) {
                $galleryImages[] = [
                    'image'     => FileService::compressAndUploadWithWatermark($file, $this->uploadFolder),
                    'item_id'   => $item->id,
                    'created_at'=> time(),
                    'updated_at'=> time(),
                ];
            }
        }

        if (count($galleryImages) > 0) {
            ItemImages::insert($galleryImages);
        }

        // 🔹 Custom fields (default)
        if ($request->custom_fields) {
            $itemCustomFieldValues = [];
            foreach ($this->decodeJsonArrayInput($request, 'custom_fields', []) as $key => $custom_field) {
                $itemCustomFieldValues[] = [
                    'item_id'         => $item->id,
                    'language_id'     => 1,
                    'custom_field_id' => $key,
                    'value'           => json_encode($custom_field, JSON_THROW_ON_ERROR),
                    'created_at'      => time(),
                    'updated_at'      => time(),
                ];
            }

            if (count($itemCustomFieldValues) > 0) {
                ItemCustomFieldValue::insert($itemCustomFieldValues);
            }
        }

        // 🔹 Custom field fajlovi
        if ($request->custom_field_files) {
            $itemCustomFieldValues = [];
            foreach ($request->custom_field_files as $key => $file) {
                $itemCustomFieldValues[] = [
                    'item_id'         => $item->id,
                    'language_id'     => 1,
                    'custom_field_id' => $key,
                    'value'           => !empty($file)
                        ? FileService::upload($file, 'custom_fields_files')
                        : '',
                    'created_at'      => time(),
                    'updated_at'      => time(),
                ];
            }

            if (count($itemCustomFieldValues) > 0) {
                ItemCustomFieldValue::insert($itemCustomFieldValues);
            }
        }

        // 🔹 Translated custom fields
        if ($request->has('custom_field_translations')) {
            $customFieldTranslations = $this->decodeJsonArrayInput($request, 'custom_field_translations', []);

            $translatedEntries = [];

            foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                    if (!is_numeric($customFieldId)) {
                        continue;
                    }
                    $translatedEntries[] = [
                        'item_id'         => $item->id,
                        'custom_field_id' => $customFieldId,
                        'language_id'     => $languageId,
                        'value'           => json_encode($translatedValue, JSON_THROW_ON_ERROR),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }
            }

            if (!empty($translatedEntries)) {
                ItemCustomFieldValue::insert($translatedEntries);
            }
        }

        // 🔹 Fetch novog item-a za response
        $result = Item::with(
            'user:id,name,email,mobile,profile,country_code',
            'category:id,name,image,is_job_category,price_optional',
            'gallery_images:id,image,item_id',
            'featured_items',
            'favourites',
            'item_custom_field_values.custom_field.translations',
            'area',
            'translations'
        )->where('id', $item->id)->get();

        $result = new ItemCollection($result);

        DB::commit();

        // ✅ Instant obavijesti pratiteljima (samo za odmah objavljene oglase)
        // Napomena: ne želimo rušiti addItem ako notifikacije/queue padnu.
        try {
            $status = (string) ($item->status ?? '');
            $isScheduled = !empty($item->scheduled_at) || $status === 'scheduled';
            if (!$isScheduled) {
                $sellerId = (int) ($item->user_id ?? $request->user()?->id);
                $sellerName = $request->user()?->name ?? null;

                // Frontend ruta za detalje oglasa (u web-u je /ad-details/[slug])
                $frontendBase = config('app.frontend_url') ?: env('FRONTEND_URL');
                $url = null;
                if (!empty($frontendBase)) {
                    $slugOrId = $item->slug ?: $item->id;
                    $url = rtrim($frontendBase, '/') . '/ad-details/' . $slugOrId;
                }

                NotifyFollowersInstant::dispatch($sellerId, [
                    'id' => $item->id,
                    'slug' => $item->slug ?? null,
                    'title' => $item->name ?? $item->title ?? 'Novi oglas',
                    'seller_name' => $sellerName,
                    'url' => $url,
                    'created_at' => $item->created_at?->toIso8601String(),
                ]);
            }
        } catch (\Throwable $e) {
            // silent fail
        }

        ResponseService::successResponse(__('Advertisement Added Successfully'), $result);
    } catch (Throwable $th) {
        DB::rollBack();
        ResponseService::logErrorResponse($th, 'API Controller -> addItem');
        ResponseService::errorResponse();
    }
}



public function getItem(Request $request)
{
    $validator = Validator::make($request->all(), [
        'limit' => 'nullable|integer',
        'offset' => 'nullable|integer',
        'id' => 'nullable',
        'custom_fields' => 'nullable',
        'slug' => 'nullable|string',
        'category_id' => 'nullable',
        'user_id' => 'nullable',
        'min_price' => 'nullable',
        'max_price' => 'nullable',
        'sort_by' => 'nullable|in:new-to-old,old-to-new,price-high-to-low,price-low-to-high,popular_items',
        'posted_since' => 'nullable|in:all-time,today,within-1-week,within-2-week,within-1-month,within-3-month',
        'current_page' => 'nullable|string',
        'compact' => 'nullable|boolean',
        'is_feature' => 'nullable',
        'placement' => 'nullable|in:category,home,category_home',
        'positions' => 'nullable|in:category,home,category_home',
    ]);
 
    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }
    try {
        $isCompactListing = filter_var($request->input('compact'), FILTER_VALIDATE_BOOLEAN);

        $itemRelations = [
            'user:id,name,email,mobile,profile,avatar_key,use_svg_avatar,created_at,is_verified,show_personal_details,country_code,response_time_avg',
            'category:id,name,image,is_job_category,price_optional',
            'gallery_images:id,image,item_id',
            'featured_items',
            'favourites',
            'area:id,name',
            'job_applications',
            'translations',
        ];

        if (! $isCompactListing) {
            $itemRelations[] = 'item_custom_field_values.custom_field.translations';
        }

        //TODO : need to simplify this whole module
        $sql = Item::with($itemRelations)
            ->withCount('featured_items')
            ->withCount('job_applications')
            ->select('items.*')
            ->whereHas('category', function ($q) {
                $q->where('status', '!=', 0)
                    ->where(function ($query) {
                        // Either no parent or parent status != 0
                        $query->whereDoesntHave('parent') // no parent category
                            ->orWhereHas('parent', function ($q2) {
                                $q2->where('status', '!=', 0);
                            });
                    });
            })
            ->when($request->id, function ($sql) use ($request) {
                $sql->where('id', $request->id);
            })->when(($request->category_id), function ($sql) use ($request) {
                $category = Category::where('id', $request->category_id)->with('children')->get();
                $categoryIDS = HelperService::findAllCategoryIds($category);
 
                return $sql->whereIn('category_id', $categoryIDS);
            })->when(($request->category_slug), function ($sql) use ($request) {
                $category = Category::where('slug', $request->category_slug)->with('children')->get();
                $categoryIDS = HelperService::findAllCategoryIds($category);
 
                return $sql->whereIn('category_id', $categoryIDS);
            })->when((isset($request->min_price) || isset($request->max_price)), function ($sql) use ($request) {
                $min_price = $request->min_price ?? 0;
                $max_price = $request->max_price ?? Item::max('price');
 
                return $sql->whereBetween('price', [$min_price, $max_price]);
            })->when($request->posted_since, function ($sql) use ($request) {
                return match ($request->posted_since) {
                    'today' => $sql->whereDate('created_at', '>=', now()),
                    'within-1-week' => $sql->whereDate('created_at', '>=', now()->subDays(7)),
                    'within-2-week' => $sql->whereDate('created_at', '>=', now()->subDays(14)),
                    'within-1-month' => $sql->whereDate('created_at', '>=', now()->subMonths()),
                    'within-3-month' => $sql->whereDate('created_at', '>=', now()->subMonths(3)),
                    default => $sql
                };
            })->when($request->area_id, function ($sql) use ($request) {
                return $sql->where('area_id', $request->area_id);
            })->when($request->user_id, function ($sql) use ($request) {
                return $sql->where('user_id', $request->user_id);
            })->when($request->slug, function ($sql) use ($request) {
                return $sql->where('slug', $request->slug);
            });

        $scarcityFilterInput = $request->input('scarcity_enabled', $request->input('is_scarcity_enabled'));
        if ($scarcityFilterInput !== null && $scarcityFilterInput !== '' && Schema::hasColumn('items', 'scarcity_enabled')) {
            $scarcityFlag = filter_var($scarcityFilterInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($scarcityFlag !== null) {
                $sql->where('scarcity_enabled', $scarcityFlag ? 1 : 0);
            }
        }

        $normalizePlacement = static function ($raw) {
            $value = strtolower(trim((string) $raw));
            return in_array($value, ['category', 'home', 'category_home'], true) ? $value : null;
        };

        $requestedPlacementFilter = $normalizePlacement($request->input('placement') ?: $request->input('positions'));
        $derivedPlacementFilter = null;
        if (! $requestedPlacementFilter) {
            $currentPage = strtolower(trim((string) $request->input('current_page')));
            if ($currentPage === 'home') {
                $derivedPlacementFilter = 'home';
            } elseif (! empty($request->category_id) || ! empty($request->category_slug)) {
                $derivedPlacementFilter = 'category';
            }
        }
        $placementFilter = $requestedPlacementFilter ?: $derivedPlacementFilter;

        $applyPlacementFilterToFeaturedQuery = static function ($featuredQuery) use ($placementFilter) {
            if (! $placementFilter) {
                return;
            }

            $featuredQuery->where(function ($placementScope) use ($placementFilter) {
                if ($placementFilter === 'home') {
                    $placementScope
                        ->whereIn('placement', ['home', 'category_home'])
                        ->orWhere(function ($legacyPlacement) {
                            $legacyPlacement
                                ->whereNull('placement')
                                ->whereIn('positions', ['home', 'category_home']);
                        });
                } elseif ($placementFilter === 'category') {
                    $placementScope
                        ->whereIn('placement', ['category', 'category_home'])
                        ->orWhere(function ($legacyPlacement) {
                            $legacyPlacement
                                ->whereNull('placement')
                                ->whereIn('positions', ['category', 'category_home']);
                        });
                } elseif ($placementFilter === 'category_home') {
                    $placementScope
                        ->where('placement', 'category_home')
                        ->orWhere(function ($legacyPlacement) {
                            $legacyPlacement
                                ->whereNull('placement')
                                ->where('positions', 'category_home');
                        });
                }
            });
        };

        $applyFeaturedPlacement = static function ($query) use ($applyPlacementFilterToFeaturedQuery) {
            $query->whereHas('featured_items', function ($featuredQuery) use ($applyPlacementFilterToFeaturedQuery) {
                $applyPlacementFilterToFeaturedQuery($featuredQuery);
            });
        };
 
        //            // Other users should only get approved items
        //            if (!Auth::check()) {
        //                $sql->where('status', 'approved');
        //            }
 
        // Sort By
 
        if ($request->sort_by == 'new-to-old') {
            $sql->orderBy('id', 'DESC');
        } elseif ($request->sort_by == 'old-to-new') {
            $sql->orderBy('id', 'ASC');
        } elseif ($request->sort_by == 'price-high-to-low') {
            $sql->orderByRaw('
                COALESCE(price, max_salary, min_salary, 0) DESC
            ');
        } elseif ($request->sort_by == 'price-low-to-high') {
            $sql->orderByRaw('
                COALESCE(price, min_salary, max_salary, 0) ASC
            ');
        } elseif ($request->sort_by == 'popular_items') {
            $sql->orderBy('clicks', 'DESC');
        } else {
            $sql->orderBy('id', 'DESC');
        }
 
        // Status
        if (! empty($request->status)) {
            if (in_array($request->status, ['review', 'approved', 'rejected', 'sold out', 'soft rejected', 'permanent rejected', 'resubmitted'])) {
                $sql->where('status', $request->status)->getNonExpiredItems()->whereNull('deleted_at');
            } elseif ($request->status == 'inactive') {
                //If status is inactive then display only trashed items
                $sql->onlyTrashed()->getNonExpiredItems();
            } elseif ($request->status == 'featured') {
                //If status is featured then display only featured items
                $sql->where('status', 'approved');
                $applyFeaturedPlacement($sql);
                $sql->getNonExpiredItems();
            } elseif ($request->status == 'expired') {
                $sql->whereNotNull('expiry_date')
                    ->where('expiry_date', '<', Carbon::now())->whereNull('deleted_at');
            }
        }

        $isFeatureInput = $request->input('is_feature');
        if ($isFeatureInput !== null && $isFeatureInput !== '') {
            $featureFlag = filter_var($isFeatureInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($featureFlag === null) {
                $normalizedFeatureFlag = strtolower(trim((string) $isFeatureInput));
                if (in_array($normalizedFeatureFlag, ['1', 'yes', 'on'], true)) {
                    $featureFlag = true;
                } elseif (in_array($normalizedFeatureFlag, ['0', 'no', 'off'], true)) {
                    $featureFlag = false;
                }
            }

            if ($featureFlag === true) {
                $sql->where('status', 'approved');
                $applyFeaturedPlacement($sql);
            } elseif ($featureFlag === false) {
                $sql->whereDoesntHave('featured_items');
            }
        }
 
        // Feature Section Filtration
        // Only apply feature section filters if user hasn't provided conflicting filters
        // User filters should override feature section defaults
        if (! empty($request->featured_section_id) || ! empty($request->featured_section_slug)) {
            if (! empty($request->featured_section_id)) {
                $featuredSection = FeatureSection::findOrFail($request->featured_section_id);
            } else {
                $featuredSection = FeatureSection::where('slug', $request->featured_section_slug)->firstOrFail();
            }
 
            // Check if user has provided filters that should override feature section filters
            $hasUserPriceFilter = isset($request->min_price) || isset($request->max_price);
            $hasUserSortFilter = !empty($request->sort_by);
            $hasUserCategoryFilter = !empty($request->category_id) || !empty($request->category_slug);
 
            // Apply feature section filters only if user hasn't provided conflicting filters
            $sql = match ($featuredSection->filter) {
                'price_criteria' => $hasUserPriceFilter
                    ? $sql // User price filter already applied, skip feature section price filter
                    // : $sql->whereBetween('price', [$featuredSection->min_price, $featuredSection->max_price]),
                    : $sql->where(function ($query) use ($featuredSection) {
                            $query->whereBetween('price', [$featuredSection->min_price, $featuredSection->max_price])
                                ->orWhere(function ($q) use ($featuredSection) {
                                    $q->whereBetween('min_salary', [$featuredSection->min_price, $featuredSection->max_price])
                                        ->whereBetween('max_salary', [$featuredSection->min_price, $featuredSection->max_price]);
                                });
                        }),
                'most_viewed' => $hasUserSortFilter
                    ? $sql // User sort already applied, skip feature section sort
                    : $sql->reorder()->orderBy('clicks', 'DESC'),
 
                'category_criteria' => $hasUserCategoryFilter
                    ? $sql // User category filter already applied, skip feature section category filter
                    : (static function () use ($featuredSection, $sql) {
                        $category = Category::whereIn('id', explode(',', $featuredSection->value))->with('children')->get();
                        $categoryIDS = HelperService::findAllCategoryIds($category);
                        return $sql->whereIn('category_id', $categoryIDS);
                    })(),
 
                'most_liked' => $hasUserSortFilter
                    ? $sql // User sort already applied, skip feature section sort
                    : $sql->reorder()->withCount('favourites'),//->orderBy('favourites_count', 'DESC'),
 
                'featured_ads' => (static function () use ($sql, $applyFeaturedPlacement) {
                    $query = $sql->where('status', 'approved');
                    $applyFeaturedPlacement($query);
                    return $query->getNonExpiredItems();
                })(),
                'all_ads' => $sql->where('status', 'approved')->getNonExpiredItems(),
                default => $sql,
            };
        }
 
        if (! empty($request->search)) {
            $sql->search($request->search);
        }
 
        function removeBackslashesRecursive($data)
        {
            $cleaned = [];
            foreach ($data as $key => $value) {
                $cleanKey = stripslashes($key);
                if (is_array($value)) {
                    $cleaned[$cleanKey] = removeBackslashesRecursive($value);
                } else {
                    $cleaned[$cleanKey] = stripslashes($value);
                }
            }
 
            return $cleaned;
        }
        $cleanedParameters = removeBackslashesRecursive($request->all());
        if (! empty($cleanedParameters['custom_fields'])) {
            $customFields = $cleanedParameters['custom_fields'];
            foreach ($customFields as $customFieldId => $value) {
                if (is_array($value)) {
                    foreach ($value as $arrayValue) {
                        $sql->join('item_custom_field_values as cf'.$customFieldId, function ($join) use ($customFieldId) {
                            $join->on('items.id', '=', 'cf'.$customFieldId.'.item_id');
                        })
                            ->where('cf'.$customFieldId.'.custom_field_id', $customFieldId)
                            ->where('cf'.$customFieldId.'.value', 'LIKE', '%'.trim($arrayValue).'%');
                    }
                } else {
                    $sql->join('item_custom_field_values as cf'.$customFieldId, function ($join) use ($customFieldId) {
                        $join->on('items.id', '=', 'cf'.$customFieldId.'.item_id');
                    })
                        ->where('cf'.$customFieldId.'.custom_field_id', $customFieldId)
                        ->where('cf'.$customFieldId.'.value', 'LIKE', '%'.trim($value).'%');
                }
            }
            $sql->whereHas('item_custom_field_values', function ($query) use ($customFields) {
                $query->whereIn('custom_field_id', array_keys($customFields));
            }, '=', count($customFields));
        }
 
        if (Auth::check()) {
            $sql->with([
                'item_offers' => function ($q) {
                    $q->where('buyer_id', Auth::user()->id);
                },
                'user_reports' => function ($q) {
                    $q->where('user_id', Auth::user()->id);
                },
            ]);
        
            $currentURI = explode('?', $request->getRequestUri(), 2);
        
            if ($currentURI[0] == '/api/my-items') {
                // Moj profil: sve moje (sa trashed)
                $sql->where(['user_id' => Auth::user()->id])->withTrashed();
            } else {
                // Ako NEMA status u requestu → podrazumijevano samo approved
                if (empty($request->status)) {
                    $sql->where('status', 'approved');
                }
                // Ako IMA status (npr. sold out), NEMOJ ga pregaziti
                $sql->has('user')
                    ->onlyNonBlockedUsers()
                    ->getNonExpiredItems();
            }
        } else {
            // Guest korisnik
            if (empty($request->status)) {
                // default – samo approved
                $sql->where('status', 'approved')
                    ->getNonExpiredItems();
            } else {
                // ako traži npr. sold out, poštuj to
                $sql->getNonExpiredItems();
            }
        }
        $locationMessage = null;

        if (empty($request->id) && empty($request->slug)) {
        // Handle location-based search with fallback logic
        // Priority: area_id > city > state > country > latitude/longitude
        // Only fallback to all items if current_page=home is passed
        $isHomePage = $request->current_page === 'home';
        // Save base query before location filters for fallback
        $baseQueryBeforeLocation = clone $sql;
        $hasLocationFilter = $request->latitude !== null && $request->longitude !== null;
        $hasCityFilter = ! empty($request->city);
        $hasStateFilter = ! empty($request->state);
        $hasCountryFilter = ! empty($request->country);
        $hasAreaFilter = ! empty($request->area_id);
        $hasAreaLocationFilter = ! empty($request->area_latitude) && ! empty($request->area_longitude);
        $cityName = $request->city ?? null;
        $stateName = $request->state ?? null;
        $countryName = $request->country ?? null;
        $areaId = $request->area_id ?? null;
        $cityItemCount = 0;
        $stateItemCount = 0;
        $countryItemCount = 0;
        $areaItemCount = 0;
 
        // Handle area location filter (find closest area by lat/long)
        if ($hasAreaLocationFilter && ! $hasAreaFilter) {
            $areaLat = $request->area_latitude;
            $areaLng = $request->area_longitude;
 
            $haversine = "(6371 * acos(cos(radians($areaLat))
                * cos(radians(latitude))
                * cos(radians(longitude) - radians($areaLng))
                + sin(radians($areaLat)) * sin(radians(latitude))))";
 
            $closestArea = Area::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->selectRaw("areas.*, {$haversine} AS distance")
                ->orderBy('distance', 'asc')
                ->first();
 
            if ($closestArea) {
                $hasAreaFilter = true;
                $areaId = $closestArea->id;
            }
        }
 
        $applyAuthFilters = function ($query) use ($request) {
            if (Auth::check()) {
                $query->with([
                    'item_offers' => function ($q) {
                        $q->where('buyer_id', Auth::user()->id);
                    },
                    'user_reports' => function ($q) {
                        $q->where('user_id', Auth::user()->id);
                    },
                ]);
        
                $currentURI = explode('?', $request->getRequestUri(), 2);
        
                if ($currentURI[0] == '/api/my-items') {
                    $query->where(['user_id' => Auth::user()->id])->withTrashed();
                } else {
                    if (empty($request->status)) {
                        $query->where('status', 'approved');
                    }
                    $query->has('user')
                          ->onlyNonBlockedUsers()
                          ->getNonExpiredItems();
                }
            } else {
                if (empty($request->status)) {
                    $query->where('status', 'approved')
                          ->getNonExpiredItems();
                } else {
                    $query->getNonExpiredItems();
                }
            }
        
            return $query;
        };
        
        
 
 
        // First, check for area filter (highest priority)
        if ($hasAreaFilter) {
            $areaQuery = clone $sql;
            $areaQuery->where('area_id', $areaId);
            $areaQuery = $applyAuthFilters($areaQuery);
            $areaItemCount = $areaQuery->exists() ? 1 : 0;
 
            if ($areaItemCount > 0) {
                $sql = $areaQuery;
            } else {
                $area = Area::find($areaId);
                $areaName = $area ? $area->name : __('the selected area');
                if ($isHomePage) {
                    $locationMessage = __('No Ads found in :area. Showing all available Ads.', ['area' => $areaName]);
                } else {
                    // Keep the area filter applied even if no items found (don't fallback)
                    $sql = $areaQuery;
                }
            }
        }
 
        // Second, check for city filter (only if area didn't find items or wasn't applied)
        if ($hasCityFilter && (! $hasAreaFilter || $areaItemCount == 0)) {
            $cityQuery = clone $sql;
            $cityQuery->where('city', $cityName);
            $cityQuery = $applyAuthFilters($cityQuery);
            $cityItemCount = $cityQuery->exists() ? 1 : 0;
 
            if ($cityItemCount > 0) {
                $sql = $cityQuery;
                if ($hasAreaFilter && $areaItemCount == 0 && $isHomePage) {
                    $locationMessage = __('No Ads found in :city. Showing all available Ads.', ['city' => $cityName]);
                }
            } else {
                if ($isHomePage) {
                    if (! $locationMessage) {
                        $locationMessage = __('No Ads found in :city. Showing all available Ads.', ['city' => $cityName]);
                    } else {
                        $area = $hasAreaFilter ? Area::find($areaId) : null;
                        $areaName = $area ? $area->name : __('the selected area');
                        $locationMessage = __('No Ads found in :area or :city. Showing all available Ads.', ['area' => $areaName, 'city' => $cityName]);
                    }
                } else {
                    // Keep the city filter applied even if no items found (don't fallback)
                    $sql = $cityQuery;
                }
            }
        }
 
        // Third, check for state filter (only if area/city didn't find items or weren't applied)
        if ($hasStateFilter && (! $hasAreaFilter || $areaItemCount == 0) && (! $hasCityFilter || $cityItemCount == 0)) {
            $stateQuery = clone $sql;
            $stateQuery->where('state', $stateName);
            $stateQuery = $applyAuthFilters($stateQuery);
            $stateItemCount = $stateQuery->exists() ? 1 : 0;
 
            if ($stateItemCount > 0) {
                $sql = $stateQuery;
                if (($hasAreaFilter && $areaItemCount == 0) || ($hasCityFilter && $cityItemCount == 0)) {
                    if ($isHomePage) {
                        $locationMessage = __('No Ads found in :state. Showing all available Ads.', ['state' => $stateName]);
                    }
                }
            } else {
                if ($isHomePage) {
                    if (! $locationMessage) {
                        $locationMessage = __('No Ads found in :state. Showing all available Ads.', ['state' => $stateName]);
                    } else {
                        $parts = [];
                        if ($hasAreaFilter && $areaItemCount == 0) {
                            $area = Area::find($areaId);
                            $parts[] = $area ? $area->name : __('the selected area');
                        }
                        if ($hasCityFilter && $cityItemCount == 0) {
                            $parts[] = $cityName;
                        }
                        $parts[] = $stateName;
                        $locationMessage = __('No Ads found in :locations. Showing all available Ads.', ['locations' => implode(', ', $parts)]);
                    }
                } else {
                    // Keep the state filter applied even if no items found (don't fallback)
                    $sql = $stateQuery;
                }
            }
        }
 
        // Fourth, check for country filter (only if area/city/state didn't find items or weren't applied)
        if ($hasCountryFilter && (! $hasAreaFilter || $areaItemCount == 0) && (! $hasCityFilter || $cityItemCount == 0) && (! $hasStateFilter || $stateItemCount == 0)) {
            $countryQuery = clone $sql;
            $countryQuery->where('country', $countryName);
            $countryQuery = $applyAuthFilters($countryQuery);
            $countryItemCount = $countryQuery->exists() ? 1 : 0;
 
            if ($countryItemCount > 0) {
                $sql = $countryQuery;
                if (($hasAreaFilter && $areaItemCount == 0) || ($hasCityFilter && $cityItemCount == 0) || ($hasStateFilter && $stateItemCount == 0)) {
                    if ($isHomePage) {
                        $locationMessage = __('No Ads found in :country. Showing all available Ads.', ['country' => $countryName]);
                    }
                }
            } else {
                if ($isHomePage) {
                    if (! $locationMessage) {
                        $locationMessage = __('No Ads found in :country. Showing all available Ads.', ['country' => $countryName]);
                    } else {
                        $parts = [];
                        if ($hasAreaFilter && $areaItemCount == 0) {
                            $area = Area::find($areaId);
                            $parts[] = $area ? $area->name : __('the selected area');
                        }
                        if ($hasCityFilter && $cityItemCount == 0) {
                            $parts[] = $cityName;
                        }
                        if ($hasStateFilter && $stateItemCount == 0) {
                            $parts[] = $stateName;
                        }
                        $parts[] = $countryName;
                        $locationMessage = __('No Ads found in :locations. Showing all available Ads.', ['locations' => implode(', ', $parts)]);
                    }
                } else {
                    // Keep the country filter applied even if no items found (don't fallback)
                    $sql = $countryQuery;
                }
            }
        }
 
 
        // Fifth, handle latitude/longitude location-based search (only if higher priority filters found items or weren't applied)
        $hasHigherPriorityFilter = ($hasAreaFilter && $areaItemCount > 0) || ($hasCityFilter && $cityItemCount > 0) || ($hasStateFilter && $stateItemCount > 0) || ($hasCountryFilter && $countryItemCount > 0);
        if ($hasLocationFilter && ((! $hasAreaFilter && ! $hasCityFilter && ! $hasStateFilter && ! $hasCountryFilter) || $hasHigherPriorityFilter)) {
            $latitude = $request->latitude;
            $longitude = $request->longitude;
            $requestedRadius = (float) ($request->radius ?? null);
 
            // Define small radius for exact location check (1 km)
            $exactLocationRadius = 1; // 1 kilometer
 
            // Build haversine formula
            $haversine = '(6371 * acos(cos(radians(?))
                * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?)) * sin(radians(latitude))))';
 
            // Clone the query for exact location check
            $exactLocationQuery = clone $sql;
            $exactLocationQuery->select('items.*')
                ->selectRaw("$haversine AS distance", [$latitude, $longitude, $latitude])
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0)
                ->having('distance', '<', $exactLocationRadius)
                ->orderBy('distance', 'asc');
 
            // Apply all other filters (status, auth, etc.) to exact location query
            if (Auth::check()) {
                $exactLocationQuery->with(['item_offers' => function ($q) {
                    $q->where('buyer_id', Auth::user()->id);
                }, 'user_reports' => function ($q) {
                    $q->where('user_id', Auth::user()->id);
                }]);
 
                $currentURI = explode('?', $request->getRequestUri(), 2);
                if ($currentURI[0] == '/api/my-items') {
                    $exactLocationQuery->where(['user_id' => Auth::user()->id])->withTrashed();
                } else {
                    $exactLocationQuery->where('status', 'approved')->has('user')->onlyNonBlockedUsers()->getNonExpiredItems();
                }
            } else {
                $exactLocationQuery->where('status', 'approved')->getNonExpiredItems();
            }
 
            // Check if items exist at exact location
            $exactLocationCount = $exactLocationQuery->exists() ? 1 : 0;
 
            if ($exactLocationCount > 0) {
                // Items found at exact location, use exact location query
                $sql = $exactLocationQuery;
                // Don't override city message if it exists
                if (! $locationMessage) {
                    $locationMessage = null; // No special message needed
                }
            } else {
                // No items at exact location, search nearby locations
                // Use requested radius if provided, otherwise use larger default radius (50 km)
                $searchRadius = $requestedRadius !== null && $requestedRadius > 0
                    ? $requestedRadius
                    : 50; // Default 50 km radius for nearby search
 
                // Clone query for nearby search
                $nearbyQuery = clone $sql;
                $nearbyQuery->select('items.*')
                    ->selectRaw("$haversine AS distance", [$latitude, $longitude, $latitude])
                    ->where('latitude', '!=', 0)
                    ->where('longitude', '!=', 0)
                    ->having('distance', '<', $searchRadius)
                    ->orderBy('distance', 'asc');
 
                // Apply auth filters to nearby query
                $nearbyQuery = $applyAuthFilters($nearbyQuery);
                $nearbyItemCount = $nearbyQuery->exists() ? 1 : 0;
 
                if ($nearbyItemCount > 0) {
                    // Items found nearby, use nearby query
                    $sql = $nearbyQuery;
                    // Set message only if no higher priority message is set
                    if (! $locationMessage) {
                        $locationMessage = __('No Ads found at your location. Showing nearby Ads.');
                    }
                } else {
                    // No items found nearby
                    if ($isHomePage) {
                        // Fallback to base query without location filter if on home page
                        $sql = clone $baseQueryBeforeLocation;
                        if (! $locationMessage) {
                            $locationMessage = __('No Ads found at your location. Showing all available Ads.');
                        } else {
                            $locationMessage = __('No Ads found at your location. Showing all available Ads.');
                        }
                    } else {
                        // Keep the location filter applied even if no items found (don't fallback)
                        $sql = $nearbyQuery;
                    }
                }
            }
        }
 
        }

        // Note: Auth filters are already applied to $baseQueryBeforeLocation,
        // so when we fallback using clone $baseQueryBeforeLocation, filters are preserved.
        // No need to re-apply filters here.
 
        // Execute query and get results
        if (! empty($request->id)) {
            /*
             * Collection does not support first OR find method's result as of now. It's a part of R&D
             * So currently using this shortcut method get() to fetch the first data
             */
            $result = $sql->get();
            if (count($result) == 0) {
                ResponseService::errorResponse(__('No item Found'));
            }
        } else {
            if (! empty($request->limit)) {
                $result = $sql->paginate($request->limit);
            } else {
                $result = $sql->paginate();
            }
        }
 
        // =====================================================
        // DODAJ SELLER SETTINGS, IS_PRO, IS_SHOP ZA SVAKI ITEM
        // =====================================================
        $items = $result instanceof \Illuminate\Pagination\LengthAwarePaginator 
            ? $result->getCollection() 
            : $result;

        $shouldAttemptLocationRepair =
            !empty($request->id) || !empty($request->slug);
        if ($shouldAttemptLocationRepair) {
            foreach ($items as $item) {
                $this->repairItemCoordinatesIfFallback($item);
            }
        }
 
        // Dohvati sve jedinstvene user_id-ove
        $userIds = $items->pluck('user_id')->unique()->filter()->values()->toArray();
 
        // Batch dohvati sve seller settings
        $sellerSettingsMap = [];
        if (!empty($userIds)) {
            $allSellerSettings = SellerSetting::whereIn('user_id', $userIds)->get();
            foreach ($allSellerSettings as $ss) {
                $sellerSettingsMap[$ss->user_id] = $ss;
            }
        }
 
        // Batch dohvati sve aktivne membership-ove
        $membershipMap = [];
        if (!empty($userIds)) {
            $allMemberships = UserMembership::whereIn('user_id', $userIds)
                ->where('status', 'active')
                ->get();
            foreach ($allMemberships as $m) {
                $membershipMap[$m->user_id] = $m;
            }
        }
 
        // Helper funkcija za određivanje Pro/Shop statusa
        $getMembershipStatus = function ($membership) {
            $isPro = false;
            $isShop = false;
 
            if ($membership) {
                // Provjeri tier kao string
                $tier = strtolower($membership->tier ?? $membership->tier_name ?? $membership->plan ?? '');
                
                if (strpos($tier, 'shop') !== false || strpos($tier, 'business') !== false) {
                    $isPro = true;
                    $isShop = true;
                } elseif (strpos($tier, 'pro') !== false || strpos($tier, 'premium') !== false) {
                    $isPro = true;
                    $isShop = false;
                }
                
                // Fallback na tier_id
                if (!$isPro && !empty($membership->tier_id)) {
                    $tierId = (int) $membership->tier_id;
                    if ($tierId === 3) { // Shop tier
                        $isPro = true;
                        $isShop = true;
                    } elseif ($tierId === 2) { // Pro tier
                        $isPro = true;
                        $isShop = false;
                    }
                }
            }
 
            return ['is_pro' => $isPro, 'is_shop' => $isShop];
        };
 
        // Dodaj seller_settings, is_pro, is_shop svakom itemu
        foreach ($items as $item) {
            $sellerId = $item->user_id;
            
            // Seller settings
            $item->seller_settings = $sellerSettingsMap[$sellerId] ?? null;
            
            // Membership status
            $membership = $membershipMap[$sellerId] ?? null;
            $membershipStatus = $getMembershipStatus($membership);
            
            $item->is_pro = $membershipStatus['is_pro'];
            $item->is_shop = $membershipStatus['is_shop'];
        }
 
        // Ako je paginator, postavi nazad kolekciju
        if ($result instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $result->setCollection($items);
        }
        // =====================================================
        // KRAJ DODAVANJA SELLER SETTINGS
        // =====================================================
 
        // Prepare response with location message if applicable
        $responseData = new ItemCollection($result);
        // Use location message if available, otherwise use default success message
        $responseMessage = !empty($locationMessage) ? $locationMessage : __('Advertisement Fetched Successfully');
 
        ResponseService::successResponse($responseMessage, $responseData);
 
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> getItem');
        ResponseService::errorResponse();
    }
}

    public function updateItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'name' => 'nullable',
            'slug' => [
                'nullable',
                'regex:/^(?!-)(?!.*--)(?!.*-$)(?!-$)[a-z0-9-]+$/',
            ],
            'price' => 'nullable',
            'description' => 'nullable',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'address' => 'nullable',
            'contact' => 'nullable',
            'image' => 'nullable|mimes:jpeg,jpg,png|max:7168',
            'custom_fields' => 'nullable',
            'custom_field_files' => 'nullable|array',
            'custom_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'gallery_images' => 'nullable|array',
            'show_only_to_premium' => 'nullable|boolean',
            'video_link' => 'nullable|url',
            'video' => 'nullable|mimetypes:video/mp4,video/quicktime,video/webm|max:51200',
            'available_now' => 'nullable|boolean',
            'is_available' => 'nullable|boolean',
            'is_avaible' => 'nullable|boolean',
            'isAvailable' => 'nullable|boolean',
            'exchange_possible' => 'nullable|boolean',
            'is_exchange' => 'nullable|boolean',
            'is_exchange_possible' => 'nullable|boolean',
            'allow_exchange' => 'nullable|boolean',
            'exchange' => 'nullable|boolean',
            'zamjena' => 'nullable|boolean',
            'zamena' => 'nullable|boolean',
            'scarcity_enabled' => 'nullable|boolean',
            'is_scarcity_enabled' => 'nullable|boolean',
            'publish_to_instagram' => 'nullable|boolean',
            'add_video_to_story' => 'nullable|boolean',
            'instagram_source_url' => 'nullable|url|max:1000',
            'price_per_unit' => 'nullable|numeric|min:0',
            'minimum_order_quantity' => 'nullable|integer|min:1|max:100000',
            'stock_alert_threshold' => 'nullable|integer|min:1|max:1000',
            'seller_product_code' => 'nullable|string|max:100',
            'campaign_badge_key' => 'nullable|string|max:80',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        DB::beginTransaction();

        try {

            $item = Item::owner()->findOrFail($request->id);
            $normalizedLocationSource = $this->normalizeLocationSourceValue($request->input('location_source'));
            if (
                $normalizedLocationSource === 'map' &&
                !$this->requestHasAnyValidCoordinatePair($request)
            ) {
                $existingLat = $item->getRawOriginal('latitude');
                $existingLng = $item->getRawOriginal('longitude');
                if ($this->isValidCoordinatePair($existingLat, $existingLng)) {
                    $request->merge([
                        'latitude' => (float) $existingLat,
                        'longitude' => (float) $existingLng,
                    ]);
                } else {
                    ResponseService::validationError('Za odabrani način "Tačan pin na mapi" označite pin na mapi.');
                }
            }
            $auto_approve_item = Setting::where('name', 'auto_approve_edited_item')->value('value') ?? 0;
            if ($auto_approve_item == 1) {
                $status = 'approved';
            } else {
                $status = 'approved';
            }
            $slugInput = $request->input('slug') ?? '';
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($slugInput)));
            $slug = trim($slug, '-');

            // If slug is empty after cleaning, use existing item slug
            if (empty($slug)) {
                $slug = $item->slug;
            }

            if ($request->has('inventory_count')) {
                $item->inventory_count = $request->inventory_count !== null && $request->inventory_count !== '' 
                    ? (int) $request->inventory_count 
                    : null;
            }

            // Generate unique slug
            $uniqueSlug = HelperService::generateUniqueSlug(new Item, $slug, $request->id);

            $data = $request->except(['video', 'image', 'gallery_images', 'custom_field_files']);
            $storyColumnExists = Schema::hasColumn('items', 'add_video_to_story');
            if (!$storyColumnExists) {
                unset($data['add_video_to_story']);
            }
            $data['slug'] = $uniqueSlug;
            $data['status'] = $status;
            if ($request->has('publish_to_instagram')) {
                $data['publish_to_instagram'] = $request->boolean('publish_to_instagram');
            }
            $storyToggleProvided = $storyColumnExists && $request->has('add_video_to_story');
            $storyRequested = $storyToggleProvided ? $request->boolean('add_video_to_story') : null;
            if ($request->has('instagram_source_url')) {
                $data['instagram_source_url'] = $request->filled('instagram_source_url')
                    ? trim((string) $request->input('instagram_source_url'))
                    : null;
            }
            if ($request->has('price_per_unit')) {
                $data['price_per_unit'] = $request->filled('price_per_unit')
                    ? (float) $request->input('price_per_unit')
                    : null;
            }
            if ($request->has('minimum_order_quantity')) {
                $data['minimum_order_quantity'] = max(1, (int) $request->input('minimum_order_quantity'));
            }
            if ($request->has('stock_alert_threshold')) {
                $data['stock_alert_threshold'] = $request->filled('stock_alert_threshold')
                    ? (int) $request->input('stock_alert_threshold')
                    : null;
            }
            if ($request->has('seller_product_code')) {
                $data['seller_product_code'] = $request->filled('seller_product_code')
                    ? trim((string) $request->input('seller_product_code'))
                    : null;
            }
            if ($request->has('campaign_badge_key')) {
                $rawCampaignBadgeKey = trim((string) $request->input('campaign_badge_key'));
                if ($rawCampaignBadgeKey === '') {
                    $data['campaign_badge_key'] = null;
                    $data['campaign_badge_label'] = null;
                } else {
                    $campaignBadgeOption = $this->resolveCampaignBadgeOption($rawCampaignBadgeKey);
                    $data['campaign_badge_key'] = $campaignBadgeOption['key'];
                    $data['campaign_badge_label'] = $campaignBadgeOption['label'];
                }
            }
            if ($request->has('scarcity_enabled') || $request->has('is_scarcity_enabled')) {
                $scarcityInput = $request->input('scarcity_enabled', $request->input('is_scarcity_enabled'));
                $data['scarcity_enabled'] = filter_var($scarcityInput ?? false, FILTER_VALIDATE_BOOLEAN);
            } else {
                $data['scarcity_enabled'] = (bool) $item->scarcity_enabled;
            }
            unset($data['is_scarcity_enabled']);

            $hasLocationInput = $request->hasAny([
                'latitude',
                'longitude',
                'location_latitude',
                'location_longitude',
                'address',
                'city',
                'state',
                'country',
                'area_id',
                'location_source',
            ]);
            if ($hasLocationInput) {
                $resolvedCoords = $this->resolveListingCoordinates($request, $item);
                $data['latitude'] = $resolvedCoords['lat'];
                $data['longitude'] = $resolvedCoords['lng'];
            }

            // image: upload file OR temp_main_image_id
            $tempMainImageId = $request->input('temp_main_image_id');
            $mainImageId = $request->input('main_image_id');
            if (!empty($tempMainImageId) && !$request->hasFile('image')) {
                $temp = TempMedia::where('id', $tempMainImageId)->where('type', 'image')->first();
                if ($temp) {
                    // delete old
                    if (!empty($item->getRawOriginal('image'))) {
                        FileService::delete($item->getRawOriginal('image'));
                    }
                    $data['image'] = $this->moveTempMediaToPermanent($temp->path, $this->uploadFolder);
                    $temp->delete();
                }
            } elseif (!empty($mainImageId) && !$request->hasFile('image')) {
                $mainImageFromGallery = ItemImages::query()
                    ->where('item_id', $item->id)
                    ->where('id', $mainImageId)
                    ->first();
                if ($mainImageFromGallery) {
                    $currentMainImagePath = $item->getRawOriginal('image');
                    $galleryImagePath = $mainImageFromGallery->getRawOriginal('image');

                    if (!empty($currentMainImagePath) && $currentMainImagePath !== $galleryImagePath) {
                        FileService::delete($currentMainImagePath);
                    }

                    $data['image'] = $galleryImagePath;
                    $mainImageFromGallery->delete();
                }
            } elseif ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplaceWithWatermark(
                    $request->file('image'),
                    $this->uploadFolder,
                    $item->getRawOriginal('image')
                );
            }
            if ($request->has('show_only_to_premium')) {
                $data['show_only_to_premium'] = $request->boolean('show_only_to_premium') ? 1 : 0;
            } else {
                $data['show_only_to_premium'] = $item->show_only_to_premium;
            }
            $availableKeys = ['available_now', 'is_available', 'is_avaible', 'isAvailable'];
            $hasAvailableInput = false;
            foreach ($availableKeys as $flagKey) {
                if ($request->has($flagKey)) {
                    $hasAvailableInput = true;
                    break;
                }
            }

            if ($hasAvailableInput) {
                $availableNowInput = $request->input(
                    'available_now',
                    $request->input(
                        'is_available',
                        $request->input('is_avaible', $request->input('isAvailable'))
                    )
                );
                $data['available_now'] = filter_var($availableNowInput ?? false, FILTER_VALIDATE_BOOLEAN);
            } else {
                $data['available_now'] = $item->available_now;
            }

            $exchangeKeys = [
                'exchange_possible',
                'is_exchange',
                'is_exchange_possible',
                'allow_exchange',
                'exchange',
                'zamjena',
                'zamena',
            ];
            $hasExchangeInput = false;
            foreach ($exchangeKeys as $flagKey) {
                if ($request->has($flagKey)) {
                    $hasExchangeInput = true;
                    break;
                }
            }

            if ($hasExchangeInput) {
                $exchangeInput = $request->input(
                    'exchange_possible',
                    $request->input(
                        'is_exchange',
                        $request->input(
                            'is_exchange_possible',
                            $request->input(
                                'allow_exchange',
                                $request->input(
                                    'exchange',
                                    $request->input('zamjena', $request->input('zamena'))
                                )
                            )
                        )
                    )
                );
                $data['exchange_possible'] = filter_var($exchangeInput ?? false, FILTER_VALIDATE_BOOLEAN);
            } else {
                $data['exchange_possible'] = $item->exchange_possible;
            }
            // Video handling:
            // - If a new video file is uploaded, store it and clear any video_link
            // - If a video_link is provided, clear stored video file (optional) and set video_link
            // - If neither is provided, keep existing values (do NOT overwrite with null/string tmp paths)
            $tempVideoId = $request->input('temp_video_id');
            if (!empty($tempVideoId) && !$request->hasFile('video') && !$request->filled('video_link')) {
                $tempV = TempMedia::where('id', $tempVideoId)->where('type', 'video')->first();
                if ($tempV) {
                    if (!empty($item->getRawOriginal('video'))) {
                        FileService::delete($item->getRawOriginal('video'));
                    }
                    $data['video'] = $this->moveTempMediaToPermanent($tempV->path, 'item_videos');
                    $data['video_link'] = null;
                    $data['video_thumbnail'] = null;
                    $data['video_duration'] = null;
                    $tempV->delete();
                }
            } elseif ($request->hasFile('video')) {
                if (!empty($item->getRawOriginal('video'))) {
                    FileService::delete($item->getRawOriginal('video'));
                }
                $data['video'] = FileService::upload($request->file('video'), 'item_videos');
                $data['video_link'] = null;
                $data['video_thumbnail'] = null;
                $data['video_duration'] = null;
            } elseif ($request->filled('video_link')) {
                // If switching to a link, remove previously stored file (if any)
                if (!empty($item->getRawOriginal('video'))) {
                    FileService::delete($item->getRawOriginal('video'));
                }
                $data['video'] = null;
                $data['video_link'] = $request->input('video_link');
                $data['video_thumbnail'] = null;
                $data['video_duration'] = null;
            } else {
                // Frontend sometimes sends video/video_link as null or as a tmp path string - ignore it
                unset($data['video']);
                if (!$request->filled('video_link')) {
                    unset($data['video_link']);
                }
                unset($data['video_thumbnail'], $data['video_duration']);
            }

            if ($storyColumnExists) {
                $effectiveRawVideo = array_key_exists('video', $data)
                    ? $data['video']
                    : $item->getRawOriginal('video');
                $effectiveVideoLink = array_key_exists('video_link', $data)
                    ? $data['video_link']
                    : $item->video_link;
                $hasVideoForStory = !empty($effectiveRawVideo) || !empty(trim((string) $effectiveVideoLink));

                if ($storyToggleProvided) {
                    if ($storyRequested && !$hasVideoForStory) {
                        ResponseService::validationError('Dodajte video ili video URL prije uključivanja story objave.');
                    }
                    if ($storyRequested) {
                        $this->ensureStorySlotAvailable((int) $item->user_id, (int) $item->id);
                    }
                    $data['add_video_to_story'] = $storyRequested && $hasVideoForStory;
                } elseif (!$hasVideoForStory) {
                    $data['add_video_to_story'] = false;
                }
            }

            if ($item->inventory_count !== null && (int) $item->inventory_count <= 0) {
                $continueSelling = (bool) optional($item->user?->sellerSettings)->continue_selling_out_of_stock;
                if (! $continueSelling) {
                    $data['status'] = 'sold out';
                }
            } elseif ($item->inventory_count !== null && (int) $item->inventory_count > 0) {
                $rawStatus = strtolower((string) ($item->getAttributes()['status'] ?? $item->status));
                if ($rawStatus === 'sold out') {
                    $data['status'] = 'approved';
                }
            }

            $item->update($data);
            // Update or create item translations
            $translations = $this->decodeJsonArrayInput($request, 'translations', []);
            if (! empty($translations)) {
                foreach ($translations as $languageId => $translationData) {
                    if (Language::where('id', $languageId)->exists()) {
                        $item->translations()->updateOrCreate(
                            ['language_id' => $languageId],
                            [
                                'name' => $translationData['name'],
                                'description' => $translationData['description'] ?? '',
                                'address' => $translationData['address'] ?? '',
                                'rejected_reason' => $translationData['rejected_reason'] ?? null,
                                'admin_edit_reason' => $translationData['admin_edit_reason'] ?? null,
                            ]
                        );
                    }
                }
            }


            if ($request->has('is_on_sale')) {
                $item->is_on_sale = filter_var($request->input('is_on_sale'), FILTER_VALIDATE_BOOLEAN);
                
                if ($item->is_on_sale && $request->filled('old_price')) {
                    $item->old_price = $request->input('old_price');
                } else if (!$item->is_on_sale) {
                    $item->old_price = null;
                }
                
                $item->save();
            }

            //Update Custom Field values for item
            if ($request->custom_fields) {
                $itemCustomFieldValues = [];
                foreach ($this->decodeJsonArrayInput($request, 'custom_fields', []) as $key => $custom_field) {
                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'custom_field_id' => $key,
                        'value' => json_encode($custom_field, JSON_THROW_ON_ERROR),
                        'updated_at' => time(),
                    ];
                }

                if (count($itemCustomFieldValues) > 0) {
                    ItemCustomFieldValue::upsert($itemCustomFieldValues, ['item_id', 'custom_field_id'], ['value', 'updated_at']);
                }
            }

            //Add new gallery images (upload file OR temp IDs)
            $galleryImages = [];

            $tempGalleryIds = $request->input('temp_gallery_image_ids');
            $tempImageIds = $request->input('temp_image_ids'); // optional fallback
            if (is_string($tempGalleryIds)) {
                $tempGalleryIds = array_values(array_filter(explode(',', $tempGalleryIds)));
            }
            if (is_string($tempImageIds)) {
                $tempImageIds = array_values(array_filter(explode(',', $tempImageIds)));
            }

            $idsToUse = [];
            if (is_array($tempGalleryIds) && count($tempGalleryIds) > 0) {
                $idsToUse = $tempGalleryIds;
            } elseif (is_array($tempImageIds) && count($tempImageIds) > 0) {
                $idsToUse = $tempImageIds;
            }

            if (count($idsToUse) > 0) {
                $temps = TempMedia::whereIn('id', $idsToUse)->where('type', 'image')->get()->keyBy('id');
                foreach ($idsToUse as $id) {
                    $t = $temps->get($id);
                    if (!$t) continue;
                    $newPath = $this->moveTempMediaToPermanent($t->path, $this->uploadFolder);
                    $galleryImages[] = [
                        'image' => $newPath,
                        'item_id' => $item->id,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                    $t->delete();
                }
            }

            if ($request->hasFile('gallery_images')) {
                foreach ($request->file('gallery_images') as $file) {
                    $galleryImages[] = [
                        'image' => FileService::compressAndUploadWithWatermark($file, $this->uploadFolder),
                        'item_id' => $item->id,
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }
            }

            if (count($galleryImages) > 0) {
                ItemImages::insert($galleryImages);
            }

            if ($request->custom_field_files) {
                $itemCustomFieldValues = [];
                foreach ($request->custom_field_files as $key => $file) {
                    $value = ItemCustomFieldValue::where(['item_id' => $item->id, 'custom_field_id' => $key])->first();
                    if (! empty($value)) {
                        $file = FileService::replace($file, 'custom_fields_files', $value->getRawOriginal('value'));
                    } else {
                        $file = '';
                    }

                    $itemCustomFieldValues[] = [
                        'item_id' => $item->id,
                        'language_id' => 1,
                        'custom_field_id' => $key,
                        'value' => $file,
                        'updated_at' => time(),
                    ];
                }

                if (count($itemCustomFieldValues) > 0) {
                    ItemCustomFieldValue::updateOrCreate(
                        ['item_id' => $item->id, 'custom_field_id' => $key],
                        ['value' => $file, 'language_id' => 1, 'updated_at' => time()]
                    );
                }
            }
            // Update or insert custom field translations
            if ($request->has('custom_field_translations')) {
                $customFieldTranslations = $this->decodeJsonArrayInput($request, 'custom_field_translations', []);
                $translatedEntries = [];

                foreach ($customFieldTranslations as $languageId => $fieldsByCustomField) {
                    foreach ($fieldsByCustomField as $customFieldId => $translatedValue) {
                        if (!is_numeric($customFieldId)) {
                            continue;
                        }
                        $translatedEntries[] = [
                            'item_id' => $item->id,
                            'custom_field_id' => $customFieldId,
                            'language_id' => $languageId,
                            'value' => json_encode($translatedValue, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }
                }

                if (! empty($translatedEntries)) {
                    // Ensure combination is unique
                    ItemCustomFieldValue::upsert(
                        $translatedEntries,
                        ['item_id', 'custom_field_id', 'language_id'], // unique keys
                        ['value', 'updated_at']
                    );
                }
            }

            //Delete gallery images
            if (! empty($request->delete_item_image_id)) {
                $item_ids = explode(',', $request->delete_item_image_id);
                foreach (ItemImages::whereIn('id', $item_ids)->get() as $itemImage) {
                    FileService::delete($itemImage->getRawOriginal('image'));
                    $itemImage->delete();
                }
            }

            $result = Item::with('user:id,name,email,mobile,profile,country_code', 'category:id,name,image,is_job_category,price_optional', 'gallery_images:id,image,item_id', 'featured_items', 'favourites', 'item_custom_field_values.custom_field.translations', 'area', 'translations')->where('id', $item->id)->get();
            /*
               * Collection does not support first OR find method's result as of now. It's a part of R&D
               * So currently using this shortcut method
              */
            $result = new ItemCollection($result);

            DB::commit();
            ResponseService::successResponse(__('Advertisement Fetched Successfully'), $result);
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> updateItem');
            ResponseService::errorResponse();
        }
    }

    public function deleteItem(Request $request)
    {
        try {
            // Validation rules
            $rules = [
                'item_id' => 'nullable|exists:items,id',
                'item_ids' => 'nullable|string', // comma-separated IDs
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            // Normalize IDs
            $itemIds = [];

            if ($request->filled('item_id')) {
                $itemIds[] = $request->item_id;
            }

            if ($request->filled('item_ids')) {
                $ids = explode(',', $request->item_ids);
                $ids = array_map('trim', $ids);
                $ids = array_filter($ids, 'strlen');
                $itemIds = array_merge($itemIds, $ids);
            }

            if (empty($itemIds)) {
                return ResponseService::validationError(__('Please provide item_id or item_ids'));
            }

            $results = [];

            foreach ($itemIds as $id) {
                try {
                    $item = Item::owner()->with('gallery_images')->withTrashed()->findOrFail($id);

                    // Delete main image
                    FileService::delete($item->getRawOriginal('image'));

                    // Delete gallery images
                    if ($item->gallery_images->count() > 0) {
                        foreach ($item->gallery_images as $gallery) {
                            FileService::delete($gallery->getRawOriginal('image'));
                        }
                    }

                    // Delete item
                    $item->forceDelete();

                    $results[] = [
                        'status' => 'success',
                        'message' => __('Advertisement Deleted Successfully'),
                        'item_id' => $id,
                    ];

                } catch (Throwable $e) {
                    $results[] = [
                        'status' => 'failed',
                        'message' => __('Failed to delete item'),
                        'item_id' => $id,
                    ];
                }
            }

            // Single item response
            if (count($results) === 1) {
                if ($results[0]['status'] === 'success') {
                    return ResponseService::successResponse(
                        __('Advertisement Deleted Successfully'),
                        ['id' => $results[0]['item_id']]
                    );
                } else {
                    return ResponseService::errorResponse($results[0]['message']);
                }
            }

            // Multiple items response
            return ResponseService::successResponse(__('Items processed successfully'), $results);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> deleteItem');

            return ResponseService::errorResponse();
        }
    }

    public function updateItemStatus(Request $request)
{
    $validator = Validator::make($request->all(), [
        'item_id'  => 'required|integer',
        'status'   => 'required|in:sold out,inactive,active,resubmitted',
        'sold_to'  => 'nullable|integer',
    ]);

    if ($validator->fails()) {
        return ResponseService::validationError($validator->errors()->first());
    }

    try {
        $item = Item::owner()
            ->whereNotIn('status', ['review', 'permanent rejected'])
            ->withTrashed()
            ->findOrFail($request->item_id);

        if ($item->status === 'permanent rejected' && $request->status === 'resubmitted') {
            return ResponseService::errorResponse(__('This Advertisement is permanently rejected and cannot be resubmitted'));
        }

        // Video upload (ako treba)
        if ($request->hasFile('video')) {
            if ($item->video) {
                Storage::disk('public')->delete($item->video);
            }
            $item->video = $request->file('video')->store('item_videos', 'public');
        }

        if ($request->status === 'inactive') {
            $item->delete();
        } elseif ($request->status === 'active') {
            $item->restore();
            $item->status = 'approved';   // ili 'active' ako ti je to pravi status u bazi
            $item->save();
        } elseif ($request->status === 'sold out') {
            $item->status = 'sold out';
            $item->sold_to = $request->sold_to;
            $item->save();

            if ($request->sold_to) {
                event(new ItemSold($item, $item->user, User::find($request->sold_to)));
            }
        } else {
            $item->status = $request->status;
            $item->save();
        }

        return ResponseService::successResponse(__('Advertisement Status Updated Successfully'));
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'ItemController -> updateItemStatus');
        return ResponseService::errorResponse(__('Something Went Wrong'));
    }
}


    public function getItemBuyerList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $buyer_ids = ItemOffer::where('item_id', $request->item_id)->select('buyer_id')->pluck('buyer_id');
            $users = User::select(['id', 'name', 'profile'])->whereIn('id', $buyer_ids)->get();
            ResponseService::successResponse(__('Buyer List fetched Successfully'), $users);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'ItemController -> updateItemStatus');
            ResponseService::errorResponse(__('Something Went Wrong'));
        }
    }

    public function getSubCategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
            'include_counts' => 'nullable|boolean',
            'tree_depth' => 'nullable|integer|min:0|max:6',
        ]);
    
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
    
        try {
            $includeCounts = filter_var(
                $request->input('include_counts', true),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
            if ($includeCounts === null) {
                $includeCounts = true;
            }

            $perPage = (int) ($request->input('per_page', 50));
            $perPage = max(1, min($perPage, 200));
            $page = max(1, (int) ($request->input('page', 1)));
            $treeDepth = (int) ($request->input('tree_depth', 5));
            $treeDepth = max(0, min($treeDepth, 6));
            $languageCode = strtolower(trim((string) ($request->header('Content-Language') ?? app()->getLocale() ?? 'default')));

            $cacheKey = 'api:get-categories:v4:'.md5(json_encode([
                'category_id' => $request->input('category_id'),
                'slug' => $request->input('slug'),
                'per_page' => $perPage,
                'page' => $page,
                'include_counts' => (int) $includeCounts,
                'tree_depth' => $treeDepth,
                'lang' => $languageCode,
            ]));

            $payload = Cache::remember($cacheKey, now()->addSeconds(120), function () use ($request, $includeCounts, $perPage, $page, $treeDepth) {
                $categoryColumns = [
                    'id',
                    'name',
                    'image',
                    'slug',
                    'status',
                    'description',
                    'is_job_category',
                    'price_optional',
                    'sequence',
                    'parent_category_id',
                    'created_at',
                    'updated_at',
                ];

                $buildCounts = function () use ($includeCounts) {
                    $counts = [
                        'subcategories' => function ($q) {
                            $q->where('status', 1);
                        },
                    ];

                    if ($includeCounts) {
                        $counts[] = 'approved_items';
                    }

                    return $counts;
                };

                $applySubcategoryScope = function ($query) use ($categoryColumns, $buildCounts) {
                    $query->select($categoryColumns)
                        ->where('status', 1)
                        ->orderByRaw('ISNULL(sequence), sequence ASC')
                        ->orderBy('id', 'ASC')
                        ->with('translations')
                        ->withCount($buildCounts());
                };

                $sql = Category::select($categoryColumns)
                    ->with('translations')
                    ->where('status', 1)
                    ->orderByRaw('ISNULL(sequence), sequence ASC')
                    ->orderBy('id', 'ASC')
                    ->withCount($buildCounts());

                if ($treeDepth > 0) {
                    $relations = [];
                    $relationPath = 'subcategories';
                    for ($depth = 0; $depth < $treeDepth; $depth++) {
                        $relations[$relationPath] = $applySubcategoryScope;
                        $relationPath .= '.subcategories';
                    }
                    $sql->with($relations);
                }

                $parentCategory = null;

                if (! empty($request->category_id)) {
                    $sql->where('parent_category_id', $request->category_id);
                } elseif (! empty($request->slug)) {
                    $parentCategory = Category::select($categoryColumns)
                        ->with('translations')
                        ->where('slug', $request->slug)
                        ->firstOrFail();
                    $sql->where('parent_category_id', $parentCategory->id);
                } else {
                    $sql->whereNull('parent_category_id');
                }

                $paginator = $sql->paginate($perPage, ['*'], 'page', $page);

                if ($includeCounts) {
                    $computeAllItemsCount = function ($category) use (&$computeAllItemsCount) {
                        $total = (int) ($category->approved_items_count ?? 0);
                        $children = $category->relationLoaded('subcategories')
                            ? $category->subcategories
                            : collect();

                        foreach ($children as $child) {
                            $total += $computeAllItemsCount($child);
                        }

                        $category->setAttribute('all_items_count', $total);

                        return $total;
                    };

                    $paginator->getCollection()->each(function ($category) use ($computeAllItemsCount) {
                        $computeAllItemsCount($category);
                    });
                }

                return [
                    'data' => $paginator->toArray(),
                    'self_category' => $parentCategory?->toArray(),
                ];
            });

            ResponseService::successResponse(null, $payload['data'], [
                'self_category' => $payload['self_category'] ?? null,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategories');
            ResponseService::errorResponse();
        }
    }
    
    public function getParentCategoryTree(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'child_category_id' => 'nullable|integer',
            'tree'             => 'nullable|boolean',
            'slug'             => 'nullable|string',
        ]);
    
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
    
        try {
            $sql = Category::with('translations')
                ->when($request->child_category_id, function ($sql) use ($request) {
                    $sql->where('id', $request->child_category_id);
                })
                ->when($request->slug, function ($sql) use ($request) {
                    $sql->where('slug', $request->slug);
                })
                ->firstOrFail()
                ->ancestorsAndSelf()
                ->breadthFirst()
                ->get();
    
            if ($request->tree) {
                $sql = $sql->toTree();
            }
    
            ResponseService::successResponse(null, $sql);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategories');
            ResponseService::errorResponse();
        }
    }
    

    public function getNotificationList()
    {
        try {
            $notifications = Notifications::with(['item.area', 'item.translations'])
                ->whereRaw('FIND_IN_SET('.Auth::user()->id.',user_id)')
                ->orWhere('send_to', 'all')
                ->orderBy('id', 'DESC')
                ->paginate();

            $currentLanguage = app()->getLocale();
            $currentLangId = Language::where('code', $currentLanguage)->value('id');

            foreach ($notifications as $notification) {
                $item = $notification->item;
                if ($item) {
                    // Load city with state and country
                    $city = City::with(['translations', 'state', 'country'])
                        ->where('name', $item->city)
                        ->whereHas('state', fn ($q) => $q->where('name', $item->state))
                        ->first();
                    $translatedArea = $item->area->translated_name ?? '';
                    $translatedCity = $city?->translated_name ?? $item->city;
                    $translatedState = $city?->state?->translated_name ?? $item->state;
                    $translatedCountry = $city?->country?->translated_name ?? $item->country;

                    // Build translated address
                    $translatedAddress =
                        (! empty($translatedArea) ? $translatedArea.', ' : '').
                        $translatedCity.', '.
                        $translatedState.', '.
                        $translatedCountry;

                    // Add translation if exists
                    if ($currentLanguage && $item->relationLoaded('translations')) {
                        $translation = $item->translations->firstWhere('language_id', $currentLangId);
                        if ($translation) {
                            $item->name = $translation->name;
                            $item->description = $translation->description;
                            $item->address = $translation->address;
                        }
                    }

                    // Attach translated data
                    $item->translated_area = $translatedArea;
                    $item->translated_city = $translatedCity;
                    $item->translated_state = $translatedState;
                    $item->translated_country = $translatedCountry;
                    $item->translated_address = $translatedAddress;
                }
            }

            ResponseService::successResponse(__('Notification fetched successfully'), $notifications);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getNotificationList');
            ResponseService::errorResponse();
        }
    }

    public function getLanguages(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'language_code' => 'required',
                'type' => 'nullable|in:app,web',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $language = Language::where('code', $request->language_code)->firstOrFail();

            // Determine requested file path
            $type = $request->type ?? 'app';
            $languageCode = $request->language_code;


            if ($type === 'web') {
                $json_file_path = base_path("resources/lang/{$language->web_file}");
                $default_file_path = base_path('resources/lang/en_web.json');
            } else {
                $json_file_path = base_path("resources/lang/{$language->app_file}");
                $default_file_path = base_path('resources/lang/en_app.json');
            }

            // If requested file doesn’t exist, fallback to default English file
            if (! is_file($json_file_path)) {
                if (is_file($default_file_path)) {
                    $json_file_path = $default_file_path;
                } else {
                    ResponseService::errorResponse(__('Default language file not found'));
                }
            }

            // Read file content safely
            $json_string = file_get_contents($json_file_path);

            try {
                $json_data = json_decode($json_string, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                ResponseService::errorResponse(__('Invalid JSON format in the language file'));
            }

            $language->file_name = $json_data;

            ResponseService::successResponse(__('Data Fetched Successfully'), $language);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getLanguages');
            ResponseService::errorResponse();
        }
    }

    public function appPaymentStatus(Request $request)
    {
        try {
            $paypalInfo = $request->all();
            if (! empty($paypalInfo) && isset($_GET['st']) && strtolower($_GET['st']) == 'completed') {
                ResponseService::successResponse(__('Your Package will be activated within 10 Minutes'), $paypalInfo['txn_id']);
            } elseif (! empty($paypalInfo) && isset($_GET['st']) && strtolower($_GET['st']) == 'authorized') {
                ResponseService::successResponse(__('Your Transaction is Completed. Ads wil be credited to your account within 30 minutes.'), $paypalInfo);
            } else {
                ResponseService::errorResponse(__('Payment Cancelled / Declined'), (isset($_GET)) ? $paypalInfo : '');
            }
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> appPaymentStatus');
            ResponseService::errorResponse();
        }
    }

    public function getPaymentSettings()
    {
        try {
            $result = PaymentConfiguration::select(['currency_code', 'payment_method', 'api_key', 'status'])->where('status', 1)->get();
            $response = [];
            foreach ($result as $payment) {
                $response[$payment->payment_method] = $payment->toArray();
            }
            $settings = Setting::whereIn('name', [
                'account_holder_name',
                'bank_name',
                'account_number',
                'ifsc_swift_code',
                'bank_transfer_status',
            ])->get();

            $bankDetails = [];
            foreach ($settings as $row) {
                $key = ($row->name === 'bank_transfer_status') ? 'status' : $row->name;
                $bankDetails[$key] = $row->value;
            }
            $response['bankTransfer'] = $bankDetails;
            ResponseService::successResponse(__('Data Fetched Successfully'), $response);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getPaymentSettings');
            ResponseService::errorResponse();
        }
    }
    public function getCustomFields(Request $request)
    {
        try {
            $filter = filter_var($request->input('filter', false), FILTER_VALIDATE_BOOLEAN);
            $categoryIds = explode(',', $request->input('category_ids'));

            // Load custom fields
            $customFields = CustomField::with('translations')
                ->whereHas('custom_field_category', function ($q) use ($categoryIds) {
                    $q->whereIn('category_id', $categoryIds);
                })
                ->where('status', 1)
                ->get();

            // Apply filtering logic
            if ($filter === true) {

                // Modify the collection with filtering
                $customFields = $customFields->filter(function ($field) use ($categoryIds) {

                    // Only filter for dropdown/checkbox/radio
                    if (!in_array($field->type, ['dropdown', 'checkbox', 'radio'])) {
                        return true; // keep text, number etc.
                    }

                    // Get used values for this field (pluck only value column)
                    $values = ItemCustomFieldValue::where('custom_field_id', $field->id)
                        ->whereHas('item', function ($q) use ($categoryIds) {
                            $q->getNonExpiredItems()
                            ->whereNull('deleted_at')
                            ->where('status', 'approved')
                            ->whereIn('category_id', $categoryIds);
                        })
                        ->pluck('value')
                        ->toArray();

                    $used = [];

                    // Decode values properly
                    foreach ($values as $raw) {
                        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

                        if (is_array($decoded)) {
                            $used = array_merge($used, $decoded);
                        } else {
                            $used[] = $decoded;
                        }
                    }

                    $used = array_unique(array_filter($used));

                    // ❌ Remove the entire field if no used values exist
                    if (empty($used)) {
                        return false;
                    }

                    // Filter original field values
                    $field->values = array_values(array_intersect($field->values ?? [], $used));

                    // Filter translations
                    foreach ($field->translations as $t) {
                        $t->value = array_values(array_intersect($t->value ?? [], $used));
                    }

                    $field->translated_value = $field->values;

                    return true; // KEEP field
                })->values(); // re-index collection
            }

            // Load translated attributes
            $customFields->each(function ($field) {
                $field->translated_name;
                $field->translated_value;
            });

            ResponseService::successResponse(__('Data Fetched successfully'), $customFields);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCustomFields');
            ResponseService::errorResponse();
        }
    }


    public function makeFeaturedItem(Request $request, FeaturedAdService $featuredAdService)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
            'placement' => 'nullable|in:category,home,category_home',
            'positions' => 'nullable|in:category,home,category_home',
            'duration_days' => 'nullable|integer|min:1|max:365',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user();
            if (! $user) {
                return ResponseService::errorResponse(__('Unauthenticated user'));
            }

            $item = Item::where('user_id', $user->id)->findOrFail($request->item_id);
            $result = $featuredAdService->assign($item, (int) $user->id, [
                'placement' => $request->input('placement') ?: $request->input('positions'),
                'duration_days' => $request->input('duration_days'),
            ]);

            if (! ($result['success'] ?? false)) {
                return ResponseService::errorResponse((string) ($result['message'] ?? 'Unable to feature advertisement right now.'));
            }

            return ResponseService::successResponse((string) ($result['message'] ?? __('Featured Advertisement Updated Successfully')), $result['meta'] ?? []);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> makeFeaturedItem');
            ResponseService::errorResponse();
        }
    }

    public function manageFavourite(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $favouriteItem = Favourite::where('user_id', Auth::user()->id)->where('item_id', $request->item_id)->first();
            if (empty($favouriteItem)) {
                $favouriteItem = new Favourite;
                $favouriteItem->user_id = Auth::user()->id;
                $favouriteItem->item_id = $request->item_id;
                $favouriteItem->save();
                ResponseService::successResponse(__('Advertisement added to Favourite'));
            } else {
                $favouriteItem->delete();
                ResponseService::successResponse(__('Advertisement remove from Favourite'));
            }
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> manageFavourite');
            ResponseService::errorResponse();
        }
    }

    public function getFavouriteItem(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer',
                'limit' => 'nullable|integer',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $favouriteItemIDS = Favourite::where('user_id', Auth::user()->id)->select('item_id')->pluck('item_id');
            $items = Item::whereIn('id', $favouriteItemIDS)
                ->with('user:id,name,email,mobile,profile,country_code', 'category:id,name,image,is_job_category', 'gallery_images:id,image,item_id', 'featured_items', 'favourites', 'item_custom_field_values.custom_field')->where('status', 'approved')->onlyNonBlockedUsers()->getNonExpiredItems()->paginate();

            ResponseService::successResponse(__('Data Fetched Successfully'), new ItemCollection($items));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getFavouriteItem');
            ResponseService::errorResponse();
        }
    }

    public function getSlider()
    {
        try {
            $rows = Slider::with(['model' => function (MorphTo $morphTo) {
                $morphTo->constrain([Category::class => function ($query) {
                    $query->withCount('subcategories');
                }]);
            }])
            // ->whereHas('model')
                ->where(function ($query) {
                    $query->whereNull('model_type')
                        ->orWhere(function ($query) {
                            $query->whereHasMorph('model', [Category::class, Item::class], function ($subQuery) {
                                $subQuery->whereNotNull('id');
                            });
                        });
                })
                ->get();
            ResponseService::successResponse(null, $rows);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getSlider');
            ResponseService::errorResponse();
        }
    }

    public function getReportReasons(Request $request)
    {
        try {
            $report_reason = new ReportReason;
            if (! empty($request->id)) {
                $id = $request->id;
                $report_reason->where('id', '=', $id);
            }
            $result = $report_reason->paginate();
            $total = $report_reason->count();
            ResponseService::successResponse(__('Data Fetched Successfully'), $result, ['total' => $total]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getReportReasons');
            ResponseService::errorResponse();
        }
    }

    public function addReports(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_id' => 'required',
                'report_reason_id' => 'required_without:other_message',
                'other_message' => 'required_without:report_reason_id',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $user = Auth::user();
            $report_count = UserReports::where('item_id', $request->item_id)->where('user_id', $user->id)->first();
            if ($report_count) {
                ResponseService::errorResponse(__('Already Reported'));
            }
            UserReports::create([
                ...$request->all(),
                'user_id' => $user->id,
                'other_message' => $request->other_message ?? '',
            ]);
            ResponseService::successResponse(__('Report Submitted Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> addReports');
            ResponseService::errorResponse();
        }
    }

    public function setItemTotalClick(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'item_id' => 'required',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            Item::findOrFail($request->item_id)->increment('clicks');
            ResponseService::successResponse(null, 'Update Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> setItemTotalClick');
            ResponseService::errorResponse();
        }
    }

    public function getFeaturedSection(Request $request)
    {
        try {
            $featureSection = FeatureSection::with('translations')->orderBy('sequence', 'ASC');

            if (isset($request->slug)) {
                $featureSection->where('slug', $request->slug);
            }
            $featureSection = $featureSection->get();
            $tempRow = [];
            $rows = [];
            $normalizePlacement = static function ($raw) {
                $value = strtolower(trim((string) $raw));
                return in_array($value, ['category', 'home', 'category_home'], true) ? $value : null;
            };

            // Pre-process location filters once (outside the loop)
            // Priority: area_id > city > state > country > latitude/longitude
            // Only fallback to all items if current_page=home is passed
            $isHomePage = $request->current_page === 'home';
            $featuredSectionPlacementFilter = $normalizePlacement($request->input('placement') ?: $request->input('positions'));
            if (! $featuredSectionPlacementFilter && $isHomePage) {
                $featuredSectionPlacementFilter = 'home';
            } elseif (! $featuredSectionPlacementFilter && (! empty($request->category_id) || ! empty($request->category_slug))) {
                $featuredSectionPlacementFilter = 'category';
            }
            $applyFeaturedSectionPlacement = static function ($query) use ($featuredSectionPlacementFilter) {
                if (! $featuredSectionPlacementFilter) {
                    return;
                }

                $query->where(function ($placementScope) use ($featuredSectionPlacementFilter) {
                    if ($featuredSectionPlacementFilter === 'home') {
                        $placementScope
                            ->whereIn('placement', ['home', 'category_home'])
                            ->orWhere(function ($legacyPlacement) {
                                $legacyPlacement
                                    ->whereNull('placement')
                                    ->whereIn('positions', ['home', 'category_home']);
                            });
                    } elseif ($featuredSectionPlacementFilter === 'category') {
                        $placementScope
                            ->whereIn('placement', ['category', 'category_home'])
                            ->orWhere(function ($legacyPlacement) {
                                $legacyPlacement
                                    ->whereNull('placement')
                                    ->whereIn('positions', ['category', 'category_home']);
                            });
                    } elseif ($featuredSectionPlacementFilter === 'category_home') {
                        $placementScope
                            ->where('placement', 'category_home')
                            ->orWhere(function ($legacyPlacement) {
                                $legacyPlacement
                                    ->whereNull('placement')
                                    ->where('positions', 'category_home');
                            });
                    }
                });
            };
            $locationMessage = null;
            $hasAreaFilter = ! empty($request->area_id);
            $hasCityFilter = ! empty($request->city);
            $hasStateFilter = ! empty($request->state);
            $hasCountryFilter = ! empty($request->country);
            $hasLocationFilter = ! empty($request->latitude) && ! empty($request->longitude);
            $hasAreaLocationFilter = ! empty($request->area_latitude) && ! empty($request->area_longitude);
            $areaId = $request->area_id ?? null;
            $areaName = null;
            $cityName = $request->city ?? null;
            $stateName = $request->state ?? null;
            $countryName = $request->country ?? null;

            // Handle area location filter (find closest area by lat/long) - do this once
            if ($hasAreaLocationFilter && ! $hasAreaFilter) {
                $areaLat = $request->area_latitude;
                $areaLng = $request->area_longitude;

                $haversine = "(6371 * acos(cos(radians($areaLat))
                    * cos(radians(latitude))
                    * cos(radians(longitude) - radians($areaLng))
                    + sin(radians($areaLat)) * sin(radians(latitude))))";

                $closestArea = Area::whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->selectRaw("areas.*, {$haversine} AS distance")
                    ->orderBy('distance', 'asc')
                    ->first();

                if ($closestArea) {
                    $hasAreaFilter = true;
                    $areaId = $closestArea->id;
                }
            }

            // Cache area name if area filter is set
            if ($hasAreaFilter) {
                $area = Area::find($areaId);
                $areaName = $area ? $area->name : __('the selected area');
            }

            // Helper function to build base query
            $buildBaseQuery = function () {
                return Item::where('status', 'approved')
                    ->with('user:id,name,email,mobile,profile,avatar_key,use_svg_avatar,created_at,is_verified,show_personal_details,country_code',
                           'category:id,name,image,is_job_category,price_optional',
                           'gallery_images:id,image,item_id',
                           'featured_items',
                           'favourites',
                           'item_custom_field_values.custom_field.translations',
                           'job_applications',
                           'translations')
                    ->has('user')
                    ->getNonExpiredItems();
            };

            foreach ($featureSection as $row) {
                // Build base query with all eager loading
                $baseItems = $buildBaseQuery();

                $sectionLocationMessage = null;
                $areaItemsFound = false;
                $cityItemsFound = false;
                $stateItemsFound = false;
                $countryItemsFound = false;

                // Apply area filter if set (highest priority)
                if ($hasAreaFilter) {
                    $baseItems->where('area_id', $areaId);
                    $areaItemsFound = (clone $baseItems)->limit(1)->exists();

                    if (! $areaItemsFound) {
                        if ($isHomePage) {
                            $sectionLocationMessage = __('No Ads found in :area. Showing all available Ads.', ['area' => $areaName]);
                            $baseItems = $buildBaseQuery();
                        }
                        // If not home page, keep the area filter applied (don't fallback)
                    }
                }

                // Apply city filter (only if area didn't find items or wasn't applied)
                if ($hasCityFilter && (! $hasAreaFilter || ! $areaItemsFound)) {
                    $baseItems->where('city', $cityName);
                    $cityItemsFound = (clone $baseItems)->limit(1)->exists();

                    if (! $cityItemsFound) {
                        if ($isHomePage) {
                            if (! $sectionLocationMessage) {
                                $sectionLocationMessage = __('No Ads found in :city. Showing all available Ads.', ['city' => $cityName]);
                            } else {
                                $sectionLocationMessage = __('No Ads found in :area or :city. Showing all available Ads.', ['area' => $areaName, 'city' => $cityName]);
                            }
                            $baseItems = $buildBaseQuery();

                            // Re-apply area filter if it found items
                            if ($hasAreaFilter && $areaItemsFound) {
                                $baseItems->where('area_id', $areaId);
                            }
                        }
                        // If not home page, keep the city filter applied (don't fallback)
                    }
                }

                // Apply state filter (only if area/city didn't find items or weren't applied)
                if ($hasStateFilter && (! $hasAreaFilter || ! $areaItemsFound) && (! $hasCityFilter || ! $cityItemsFound)) {
                    $baseItems->where('state', $stateName);
                    $stateItemsFound = (clone $baseItems)->limit(1)->exists();

                    if (! $stateItemsFound) {
                        if ($isHomePage) {
                            if (! $sectionLocationMessage) {
                                $sectionLocationMessage = __('No Ads found in :state. Showing all available Ads.', ['state' => $stateName]);
                            } else {
                                $parts = [];
                                if ($hasAreaFilter && ! $areaItemsFound) {
                                    $parts[] = $areaName;
                                }
                                if ($hasCityFilter && ! $cityItemsFound) {
                                    $parts[] = $cityName;
                                }
                                $parts[] = $stateName;
                                $sectionLocationMessage = __('No Ads found in :locations. Showing all available Ads.', ['locations' => implode(', ', $parts)]);
                            }
                            $baseItems = $buildBaseQuery();

                            // Re-apply higher priority filters if they found items
                            if ($hasAreaFilter && $areaItemsFound) {
                                $baseItems->where('area_id', $areaId);
                            }
                            if ($hasCityFilter && $cityItemsFound) {
                                $baseItems->where('city', $cityName);
                            }
                        }
                        // If not home page, keep the state filter applied (don't fallback)
                    }
                }

                // Apply country filter (only if area/city/state didn't find items or weren't applied)
                if ($hasCountryFilter && (! $hasAreaFilter || ! $areaItemsFound) && (! $hasCityFilter || ! $cityItemsFound) && (! $hasStateFilter || ! $stateItemsFound)) {
                    $baseItems->where('country', $countryName);
                    $countryItemsFound = (clone $baseItems)->limit(1)->exists();

                    if (! $countryItemsFound) {
                        if ($isHomePage) {
                            if (! $sectionLocationMessage) {
                                $sectionLocationMessage = __('No Ads found in :country. Showing all available Ads.', ['country' => $countryName]);
                            } else {
                                $parts = [];
                                if ($hasAreaFilter && ! $areaItemsFound) {
                                    $parts[] = $areaName;
                                }
                                if ($hasCityFilter && ! $cityItemsFound) {
                                    $parts[] = $cityName;
                                }
                                if ($hasStateFilter && ! $stateItemsFound) {
                                    $parts[] = $stateName;
                                }
                                $parts[] = $countryName;
                                $sectionLocationMessage = __('No Ads found in :locations. Showing all available Ads.', ['locations' => implode(', ', $parts)]);
                            }
                            $baseItems = $buildBaseQuery();

                            // Re-apply higher priority filters if they found items
                            if ($hasAreaFilter && $areaItemsFound) {
                                $baseItems->where('area_id', $areaId);
                            }
                            if ($hasCityFilter && $cityItemsFound) {
                                $baseItems->where('city', $cityName);
                            }
                            if ($hasStateFilter && $stateItemsFound) {
                                $baseItems->where('state', $stateName);
                            }
                        }
                        // If not home page, keep the country filter applied (don't fallback)
                    }
                }

                // Handle item lat/long filtering (for items themselves)
                if ($hasLocationFilter) {
                    $latitude = $request->latitude;
                    $longitude = $request->longitude;
                    $requestedRadius = isset($request->radius) ? (float) $request->radius : null;

                    // Haversine formula
                    $haversine = "(6371 * acos(cos(radians($latitude))
                                    * cos(radians(latitude))
                                    * cos(radians(longitude) - radians($longitude))
                                    + sin(radians($latitude)) * sin(radians(latitude))))";

                    // Check exact location first (1 km radius)
                    $exactLocationRadius = 1;
                    $exactLocationQuery = clone $baseItems;
                    $exactLocationQuery->select('items.*')
                        ->selectRaw("{$haversine} AS distance")
                        ->where('latitude', '!=', 0)
                        ->where('longitude', '!=', 0)
                        ->having('distance', '<', $exactLocationRadius)
                        ->orderBy('distance', 'asc');

                    $exactLocationFound = $exactLocationQuery->limit(1)->exists();

                    if ($exactLocationFound) {
                        // Items found at exact location, use exact location query
                        $baseItems = $exactLocationQuery;
                    } else {
                        // No items at exact location, search nearby
                        $searchRadius = $requestedRadius !== null && $requestedRadius > 0
                            ? $requestedRadius
                            : 50; // Default 50 km radius for nearby search

                        $nearbyQuery = clone $baseItems;
                        $nearbyQuery->select('items.*')
                            ->selectRaw("{$haversine} AS distance")
                            ->where('latitude', '!=', 0)
                            ->where('longitude', '!=', 0)
                            ->having('distance', '<', $searchRadius)
                            ->orderBy('distance', 'asc');

                        $nearbyItemsFound = $nearbyQuery->limit(1)->exists();

                        if ($nearbyItemsFound) {
                            // Items found nearby, use nearby query
                            $baseItems = $nearbyQuery;
                            if (! $sectionLocationMessage && $isHomePage) {
                                $sectionLocationMessage = __('No Ads found at your location. Showing nearby Ads.');
                            }
                        } else {
                            // No items found nearby
                            if ($isHomePage) {
                                // Fallback to all items if on home page
                                $baseItems = $buildBaseQuery();
                                // Re-apply higher priority filters if they found items
                                if ($hasAreaFilter && $areaItemsFound) {
                                    $baseItems->where('area_id', $areaId);
                                }
                                if ($hasCityFilter && $cityItemsFound) {
                                    $baseItems->where('city', $cityName);
                                }
                                if ($hasStateFilter && $stateItemsFound) {
                                    $baseItems->where('state', $stateName);
                                }
                                if ($hasCountryFilter && $countryItemsFound) {
                                    $baseItems->where('country', $countryName);
                                }
                                if (! $sectionLocationMessage) {
                                    $sectionLocationMessage = __('No Ads found at your location. Showing all available Ads.');
                                }
                            } else {
                                // Keep the location filter applied even if no items found (don't fallback)
                                $baseItems = $nearbyQuery;
                            }
                        }
                    }
                }

                // Apply filter criteria
                $items = match ($row->filter) {
                   // 'price_criteria' => $baseItems->whereBetween('price', [$row->min_price, $row->max_price]),
			        'price_criteria' => $baseItems->where(function ($query) use ($row) {
                                        $query->whereBetween('price', [$row->min_price, $row->max_price])
                                            ->orWhere(function ($q) use ($row) {
                                                $q->whereBetween('min_salary', [$row->min_price, $row->max_price])
                                                    ->whereBetween('max_salary', [$row->min_price, $row->max_price]);
                                            });
                                    }),
                    'most_viewed' => $baseItems->orderBy('clicks', 'DESC'),
                    'category_criteria' => (static function () use ($row, $baseItems) {
                        $category = Category::whereIn('id', explode(',', $row->value))->with('children')->get();
                        $categoryIDS = HelperService::findAllCategoryIds($category);

                        return $baseItems->whereIn('category_id', $categoryIDS)->orderBy('id', 'DESC');
                    })(),
                    'most_liked' => $baseItems->withCount('favourites')->orderBy('favourites_count', 'DESC'),
                    'featured_ads' => (static function () use ($baseItems, $applyFeaturedSectionPlacement) {
                        return $baseItems->whereHas('featured_items', function ($query) use ($applyFeaturedSectionPlacement) {
                            $applyFeaturedSectionPlacement($query);
                        })->orderBy('id', 'DESC');
                    })(),
                    'all_ads' => $baseItems->orderBy('id', 'DESC'),
                    default => $baseItems->orderBy('id', 'DESC'),
                };

                // Add auth-specific relationships
                if (Auth::check()) {
                    $items->with(['item_offers' => function ($q) {
                        $q->where('buyer_id', Auth::user()->id);
                    }, 'user_reports' => function ($q) {
                        $q->where('user_id', Auth::user()->id);
                    }]);
                }

                // For most sections keep compact preview; for "all_ads" return all unless explicit section_limit is sent.
                $requestedSectionLimit = (int) $request->input('section_limit', 0);
                $sectionLimit = $requestedSectionLimit > 0 ? min($requestedSectionLimit, 500) : null;
                if ($row->filter !== 'all_ads' && $sectionLimit === null) {
                    $sectionLimit = 5;
                }

                $items = $sectionLimit === null
                    ? $items->get()
                    : $items->limit($sectionLimit)->get();

                $tempRow[$row->id] = $row;
                $tempRow[$row->id]['total_data'] = count($items);
                if (count($items) > 0) {
                    $tempRow[$row->id]['section_data'] = new ItemCollection($items);
                } else {
                    $tempRow[$row->id]['section_data'] = [];
                }

                // Track location message for response (use first non-empty one)
                if (!empty($sectionLocationMessage) && empty($locationMessage)) {
                    $locationMessage = $sectionLocationMessage;
                }

                $rows[] = $tempRow[$row->id];
            }

            // Use location message if available, otherwise use default success message
            $responseMessage = !empty($locationMessage) ? $locationMessage : __('Data Fetched Successfully');
            ResponseService::successResponse($responseMessage, $rows);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getFeaturedSection');
            ResponseService::errorResponse();
        }
    }

    public function getPaymentIntent(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'package_id' => 'required',
            'payment_method' => 'required|in:Stripe,Razorpay,Paystack,PhonePe,FlutterWave,bankTransfer,PayPal',
            'platform_type' => 'required_if:payment_method,==,Paystack|string',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            DB::beginTransaction();

            if ($request->payment_method !== 'bankTransfer') {
                $paymentConfigurations = PaymentConfiguration::where(['status' => 1, 'payment_method' => $request->payment_method])->first();
                if (empty($paymentConfigurations)) {
                    ResponseService::errorResponse(__('Payment is not Enabled'));
                }
            } else {
                $bankTransferEnabled = Setting::where('name', 'bank_transfer_status')->value('value');
                if ($bankTransferEnabled != 1) {
                    ResponseService::errorResponse(__('Bank Transfer is not enabled.'));
                }
            }

            $package = Package::whereNot('final_price', 0)->findOrFail($request->package_id);

            $purchasedPackage = UserPurchasedPackage::onlyActive()->where(['user_id' => Auth::user()->id, 'package_id' => $request->package_id])->first();
            if (! empty($purchasedPackage)) {
                ResponseService::errorResponse(__('You already have purchased this package'));
            }
            if ($request->payment_method === 'bankTransfer') {
                $existingTransaction = PaymentTransaction::where('user_id', Auth::user()->id)
                    ->where('package_id', $request->package_id)
                    ->where('payment_gateway', $request->payment_method)
                    // ->whereIn('payment_status', ['pending', 'under review'])
                    ->exists();

                $methodName = $paymentMethodNames[$request->payment_method] ?? ucfirst($request->payment_method);

                if ($existingTransaction) {
                    return ResponseService::errorResponse("A $methodName transaction for this package already exists.");
                }
            }
            $orderId = ($request->payment_method === 'bankTransfer') ? uniqid().'-'.'p'.'-'.$package->id : null;

            //Add Payment Data to Payment Transactions Table
            $paymentTransactionData = PaymentTransaction::create([
                'user_id' => Auth::user()->id,
                'package_id' => $request->package_id,
                'amount' => $package->final_price,
                'payment_gateway' => ucfirst($request->payment_method),
                'payment_status' => 'Pending',
                'order_id' => $orderId,
            ]);

            if ($request->payment_method === 'bankTransfer') {
                DB::commit();
                ResponseService::successResponse(__('Bank transfer initiated. Please complete the transfer and update the transaction.'), [
                    'payment_transaction_id' => $paymentTransactionData->id,
                    'payment_transaction' => $paymentTransactionData,
                ]);
            }

            $paymentIntent = PaymentService::create($request->payment_method)->createAndFormatPaymentIntent(round($package->final_price, 2), [
                'payment_transaction_id' => $paymentTransactionData->id,
                'package_id' => $package->id,
                'user_id' => Auth::user()->id,
                'email' => Auth::user()->email,
                'platform_type' => $request->platform_type,
            ]);
            $paymentTransactionData->update(['order_id' => $paymentIntent['id']]);

            $paymentTransactionData = PaymentTransaction::findOrFail($paymentTransactionData->id);
            // Custom Array to Show as response
            $paymentGatewayDetails = [
                ...$paymentIntent,
                'payment_transaction_id' => $paymentTransactionData->id,
            ];

            DB::commit();
            ResponseService::successResponse('', ['payment_intent' => $paymentGatewayDetails, 'payment_transaction' => $paymentTransactionData]);
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentTransactions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latest_only' => 'nullable|boolean',
            'page' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $paymentTransactions = PaymentTransaction::where('user_id', Auth::user()->id)->orderBy('id', 'DESC');
            if ($request->latest_only) {
                $paymentTransactions->where('created_at', '>', Carbon::now()->subMinutes(30)->toDateTimeString());
            }
            $paymentTransactions = $paymentTransactions->paginate();

            $paymentTransactions->getCollection()->transform(function ($data) {
                if ($data->payment_status == 'pending') {
                    try {
                        $paymentIntent = PaymentService::create($data->payment_gateway)->retrievePaymentIntent($data->order_id);
                    } catch (Throwable) {
                        //                        PaymentTransaction::find($data->id)->update(['payment_status' => "failed"]);
                    }

                    if (! empty($paymentIntent) && $paymentIntent['status'] != 'pending') {
                        PaymentTransaction::find($data->id)->update(['payment_status' => $paymentIntent['status'] ?? 'failed']);
                    }
                }
                $data->payment_reciept = $data->payment_reciept;

                return $data;
            });

            ResponseService::successResponse(__('Payment Transactions Fetched'), $paymentTransactions);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function createItemOffer(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
            'amount' => 'nullable|numeric',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $item = Item::approved()->notOwner()->findOrFail($request->item_id);
            $itemOffer = ItemOffer::updateOrCreate([
                'item_id' => $request->item_id,
                'buyer_id' => Auth::user()->id,
                'seller_id' => $item->user_id,
            ], ['amount' => $request->amount]);

            // ✅ RESTORE ako je buyer ranije obrisao chat
                $userId = Auth::user()->id;

                // deleted_by
                $deletedBy = $itemOffer->deleted_by ?? '[]';
                $deletedBy = is_string($deletedBy) ? (json_decode($deletedBy, true) ?? []) : (array)$deletedBy;

                // deleted_at_by (OVO mora biti objekat/mapa)
                $deletedAtBy = $itemOffer->deleted_at_by ?? '{}';
                $deletedAtBy = is_string($deletedAtBy) ? (json_decode($deletedAtBy, true) ?? []) : (array)$deletedAtBy;

                $changed = false;

                // ako je bio obrisan od ovog usera -> vrati chat
                if (in_array($userId, $deletedBy, true)) {
                    $deletedBy = array_values(array_filter($deletedBy, fn($id) => (int)$id !== (int)$userId));
                    unset($deletedAtBy[$userId]);
                    $changed = true;
                }

                if ($changed) {
                    $itemOffer->deleted_by = json_encode($deletedBy);
                    $itemOffer->deleted_at_by = json_encode($deletedAtBy);
                    $itemOffer->save();
                }

            $itemOffer = $itemOffer->load('seller:id,name,profile', 'buyer:id,name,profile', 'item:id,name,description,price,image');

            $fcmMsg = [
                'user_id' => $itemOffer->buyer->id,
                'user_name' => $itemOffer->buyer->name,
                'user_profile' => $itemOffer->buyer->profile,
                'user_type' => 'Buyer',
                'item_id' => $itemOffer->item->id,
                'item_name' => $itemOffer->item->name,
                'item_image' => $itemOffer->item->image,
                'item_price' => $itemOffer->item->price,
                'item_offer_id' => $itemOffer->id,
                'item_offer_amount' => $itemOffer->amount,
                // 'type'              => $notificationPayload['message_type'],
                // 'message_type_temp' => $notificationPayload['message_type']
            ];
            /* message_type is reserved keyword in FCM so removed here*/
            unset($fcmMsg['message_type']);
            if ($request->has('amount') && $request->amount != 0) {
                $user_token = UserFcmToken::where('user_id', $item->user->id)->pluck('fcm_token')->toArray();
                $message = 'new offer is created by buyer';
                NotificationService::sendFcmNotification($user_token, 'New Offer', $message, 'offer', $fcmMsg);
            }

            ResponseService::successResponse(__('Advertisement Offer Created Successfully'), $itemOffer);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> createItemOffer');
            ResponseService::errorResponse();
        }
    }

    public function getMyOffers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:received,sent,all',
            'page' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $type = $request->input('type', 'received');

            $query = ItemOffer::with([
                'item:id,name,price,image,user_id',
                'seller:id,name,profile',
                'buyer:id,name,profile',
            ]);

            if ($type === 'sent') {
                $query->where('buyer_id', $user->id);
            } elseif ($type === 'received') {
                $query->where('seller_id', $user->id);
            } else {
                $query->where(function ($subQuery) use ($user) {
                    $subQuery->where('seller_id', $user->id)
                        ->orWhere('buyer_id', $user->id);
                });
            }

            $offers = $query->orderBy('updated_at', 'desc')->paginate(20);

            ResponseService::successResponse(__('Offers fetched successfully'), $offers);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getMyOffers');
            ResponseService::errorResponse();
        }
    }

    public function acceptOffer(int $id)
    {
        try {
            $user = Auth::user();
            $offer = ItemOffer::with([
                'item:id,name,price,image',
                'seller:id,name,profile',
                'buyer:id,name,profile',
            ])->findOrFail($id);

            if ((int) $offer->seller_id !== (int) $user->id) {
                ResponseService::errorResponse(__('Unauthorized to accept this offer.'), 403);
            }

            if ($offer->status === 'accepted') {
                ResponseService::successResponse(__('Offer already accepted.'), $offer);
            }

            if ($offer->status === 'rejected') {
                ResponseService::errorResponse(__('Offer already rejected.'), 422);
            }

            $offer->status = 'accepted';
            $offer->save();

            $buyerTokens = UserFcmToken::where('user_id', $offer->buyer_id)->pluck('fcm_token')->toArray();
            if (! empty($buyerTokens)) {
                $payload = [
                    'item_id' => $offer->item->id ?? null,
                    'item_name' => $offer->item->name ?? null,
                    'item_image' => $offer->item->image ?? null,
                    'item_price' => $offer->item->price ?? null,
                    'item_offer_id' => $offer->id,
                    'item_offer_amount' => $offer->amount,
                    'status' => $offer->status,
                ];

                NotificationService::sendFcmNotification(
                    $buyerTokens,
                    'Offer Accepted',
                    'Your offer has been accepted.',
                    'offer-status',
                    $payload
                );
            }

            ResponseService::successResponse(__('Offer accepted successfully.'), $offer);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> acceptOffer');
            ResponseService::errorResponse();
        }
    }

    public function rejectOffer(int $id)
    {
        try {
            $user = Auth::user();
            $offer = ItemOffer::with([
                'item:id,name,price,image',
                'seller:id,name,profile',
                'buyer:id,name,profile',
            ])->findOrFail($id);

            if ((int) $offer->seller_id !== (int) $user->id) {
                ResponseService::errorResponse(__('Unauthorized to reject this offer.'), 403);
            }

            if ($offer->status === 'rejected') {
                ResponseService::successResponse(__('Offer already rejected.'), $offer);
            }

            if ($offer->status === 'accepted') {
                ResponseService::errorResponse(__('Offer already accepted.'), 422);
            }

            $offer->status = 'rejected';
            $offer->save();

            $buyerTokens = UserFcmToken::where('user_id', $offer->buyer_id)->pluck('fcm_token')->toArray();
            if (! empty($buyerTokens)) {
                $payload = [
                    'item_id' => $offer->item->id ?? null,
                    'item_name' => $offer->item->name ?? null,
                    'item_image' => $offer->item->image ?? null,
                    'item_price' => $offer->item->price ?? null,
                    'item_offer_id' => $offer->id,
                    'item_offer_amount' => $offer->amount,
                    'status' => $offer->status,
                ];

                NotificationService::sendFcmNotification(
                    $buyerTokens,
                    'Offer Rejected',
                    'Your offer has been rejected.',
                    'offer-status',
                    $payload
                );
            }

            ResponseService::successResponse(__('Offer rejected successfully.'), $offer);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> rejectOffer');
            ResponseService::errorResponse();
        }
    }

    public function counterOffer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'offer_id' => 'required|integer|exists:item_offers,id',
            'amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $offer = ItemOffer::with([
                'item:id,name,price,image',
                'seller:id,name,profile',
                'buyer:id,name,profile',
            ])->findOrFail($request->offer_id);

            if ((int) $offer->seller_id !== (int) $user->id) {
                ResponseService::errorResponse(__('Unauthorized to counter this offer.'), 403);
            }

            if (in_array($offer->status, ['accepted', 'rejected'], true)) {
                ResponseService::errorResponse(__('Offer can no longer be countered.'), 422);
            }

            $offer->amount = $request->amount;
            $offer->status = 'countered';
            $offer->save();

            $buyerTokens = UserFcmToken::where('user_id', $offer->buyer_id)->pluck('fcm_token')->toArray();
            if (! empty($buyerTokens)) {
                $payload = [
                    'item_id' => $offer->item->id ?? null,
                    'item_name' => $offer->item->name ?? null,
                    'item_image' => $offer->item->image ?? null,
                    'item_price' => $offer->item->price ?? null,
                    'item_offer_id' => $offer->id,
                    'item_offer_amount' => $offer->amount,
                    'status' => $offer->status,
                ];

                NotificationService::sendFcmNotification(
                    $buyerTokens,
                    'Counter Offer',
                    'Seller sent a counter-offer.',
                    'offer-status',
                    $payload
                );
            }

            ResponseService::successResponse(__('Counter offer sent successfully.'), $offer);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> counterOffer');
            ResponseService::errorResponse();
        }
    }

    public function getChatList(Request $request)
{
    $type = $request->query('type', 'buyer');
    $userId = auth()->id();

    if (!$userId) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    try {
        $query = ItemOffer::with(['item.user', 'seller', 'buyer']);

        if ($type === 'archived') {
            // Samo arhivirani chatovi
            $query->where(function($q) use ($userId) {
                $q->where('buyer_id', $userId)
                  ->orWhereHas('item', function ($q2) use ($userId) {
                      $q2->where('user_id', $userId);
                  });
            });
        } elseif ($type === 'buyer') {
            $query->where('buyer_id', $userId);
        } else {
            $query->whereHas('item', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        $itemOffers = $query->orderBy('updated_at', 'desc')->paginate(20);

        // Transform i filtriraj
        $itemOffers->setCollection(
            $itemOffers->getCollection()->map(function ($offer) use ($userId, $type) {
                // Parse JSON fields
                $pinnedBy = $offer->pinned_by ?? '[]';
                if (is_string($pinnedBy)) {
                    $pinnedBy = json_decode($pinnedBy, true) ?? [];
                }
                
                $archivedBy = $offer->archived_by ?? '[]';
                if (is_string($archivedBy)) {
                    $archivedBy = json_decode($archivedBy, true) ?? [];
                }
                
                $deletedBy = $offer->deleted_by ?? '[]';
                if (is_string($deletedBy)) {
                    $deletedBy = json_decode($deletedBy, true) ?? [];
                }
                
                $deletedAtBy = $offer->deleted_at_by ?? '{}';
                if (is_string($deletedAtBy)) {
                    $deletedAtBy = json_decode($deletedAtBy, true) ?? [];
                }

                $offer->is_pinned = in_array($userId, $pinnedBy);
                $offer->is_archived = in_array($userId, $archivedBy);

                // Get last message
                $lastChat = \DB::table('chats')
                    ->where('item_offer_id', $offer->id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $offer->last_message = $lastChat->message ?? null;
                $offer->last_message_type = $lastChat->type ?? 'text';
                $offer->last_message_status = ($lastChat && $lastChat->sender_id == $userId) ? ($lastChat->status ?? 'sent') : null;
                $offer->last_message_sender_id = $lastChat->sender_id ?? null;
                $offer->last_message_time = $lastChat->created_at ?? null;

                // 🔥 NOVO: Provjeri da li je obrisano i da li ima novih poruka
                $userDeletedAt = $deletedAtBy[$userId] ?? null;
                
                if (in_array($userId, $deletedBy) && $userDeletedAt) {
                    // Korisnik je obrisao chat - provjeri ima li novih poruka NAKON brisanja
                    $hasNewMessages = \DB::table('chats')
                        ->where('item_offer_id', $offer->id)
                        ->where('sender_id', '!=', $userId)
                        ->where('created_at', '>', $userDeletedAt)
                        ->exists();
                    
                    if ($hasNewMessages) {
                        // Ima novih poruka - ukloni iz deleted liste (chat se vraća)
                        $offer->is_deleted = false;
                        $offer->show_chat = true;
                        
                        // Broji samo NOVE nepročitane poruke (nakon brisanja)
                        $offer->unread_chat_count = \DB::table('chats')
                            ->where('item_offer_id', $offer->id)
                            ->where('sender_id', '!=', $userId)
                            ->where('status', '!=', 'seen')
                            ->where('created_at', '>', $userDeletedAt)
                            ->count();
                    } else {
                        // Nema novih poruka - chat ostaje skriven
                        $offer->is_deleted = true;
                        $offer->show_chat = false;
                    }
                } else {
                    // Chat nije obrisan
                    $offer->is_deleted = false;
                    $offer->show_chat = true;
                    
                    $offer->unread_chat_count = \DB::table('chats')
                        ->where('item_offer_id', $offer->id)
                        ->where('sender_id', '!=', $userId)
                        ->where('status', '!=', 'seen')
                        ->count();
                }

                $offer->is_online = false;
                $offer->is_typing = false;

                return $offer;
            })->filter(function ($offer) use ($type) {
                // Filtriraj po tipu i show_chat
                if (!$offer->show_chat) {
                    return false;
                }
                
                if ($type === 'archived') {
                    return $offer->is_archived;
                } else {
                    return !$offer->is_archived;
                }
            })->values()
        );

        return response()->json($itemOffers);

    } catch (\Exception $e) {
        \Log::error('getChatList error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    

    public function sendTypingIndicator(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|integer',
            'is_typing' => 'required|boolean',
        ]);
    
        broadcast(new \App\Events\UserTyping(
            $validated['chat_id'],
            auth()->id(),
            $validated['is_typing']
        ))->toOthers();
    
        return response()->json(['success' => true]);
    }
    
/**
 * Mark messages as seen
 */
public function markAsSeen(Request $request)
{
    $userId = auth()->id();
    $chatId = $request->input('chat_id');
    
    if (!$userId || !$chatId) {
        return response()->json(['error' => 'Invalid request'], 400);
    }

    try {
        // Update all messages in this chat that are NOT sent by current user
        $updated = \DB::table('chats')
            ->where('item_offer_id', $chatId)
            ->where('sender_id', '!=', $userId)
            ->where('status', '!=', 'seen')
            ->update([
                'status' => 'seen',
                'updated_at' => now()
            ]);

        // Broadcast status update via Reverb (optional)
        // You can add event broadcasting here if needed

        return response()->json([
            'success' => true,
            'updated' => $updated
        ]);

    } catch (\Exception $e) {
        \Log::error('markAsSeen error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


public function sendMessage(Request $request)
{
    $validator = Validator::make($request->all(), [
        'item_offer_id' => 'required|integer',
        'message' => (! $request->file('file') && ! $request->file('audio')) ? 'required' : 'nullable',
        'file' => 'nullable|mimes:jpg,jpeg,png|max:7168',
        'audio' => 'nullable|mimetypes:audio/mpeg,video/webm,audio/ogg,video/mp4,audio/x-wav,text/plain|max:7168',
    ]);

    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }

    try {
        DB::beginTransaction();

        $user = Auth::user();

        $authUserBlockList = BlockUser::where('user_id', $user->id)->get();
        $otherUserBlockList = BlockUser::where('blocked_user_id', $user->id)->get();

        $itemOffer = ItemOffer::with('item')->findOrFail($request->item_offer_id);

        // Block check (tvoj postojeći kod)
        if ($itemOffer->seller_id == $user->id) {
            $blockStatus = $authUserBlockList->filter(function ($data) use ($itemOffer) {
                return $data->user_id == $itemOffer->seller_id && $data->blocked_user_id == $itemOffer->buyer_id;
            });
            if (count($blockStatus) !== 0) {
                ResponseService::errorResponse(__('You Cannot send message because You have blocked this user'));
            }

            $blockStatus = $otherUserBlockList->filter(function ($data) use ($itemOffer) {
                return $data->user_id == $itemOffer->buyer_id && $data->blocked_user_id == $itemOffer->seller_id;
            });
            if (count($blockStatus) !== 0) {
                ResponseService::errorResponse(__('You Cannot send message because other user has blocked you.'));
            }
        } else {
            $blockStatus = $authUserBlockList->filter(function ($data) use ($itemOffer) {
                return $data->user_id == $itemOffer->buyer_id && $data->blocked_user_id == $itemOffer->seller_id;
            });
            if (count($blockStatus) !== 0) {
                ResponseService::errorResponse(__('You Cannot send message because You have blocked this user'));
            }

            $blockStatus = $otherUserBlockList->filter(function ($data) use ($itemOffer) {
                return $data->user_id == $itemOffer->seller_id && $data->blocked_user_id == $itemOffer->buyer_id;
            });
            if (count($blockStatus) !== 0) {
                ResponseService::errorResponse(__('You Cannot send message because other user has blocked you.'));
            }
        }

        // Message type (tvoj postojeći kod)
        $messageType = 'text';
        if ($request->hasFile('file') && $request->filled('message')) {
            $messageType = 'file_and_text';
        } elseif ($request->hasFile('file')) {
            $messageType = 'file';
        } elseif ($request->hasFile('audio')) {
            $messageType = 'audio';
        }

        // Kreiraj poruku (tvoj postojeći kod)
        $chat = Chat::create([
            'sender_id' => $user->id,
            'item_offer_id' => $request->item_offer_id,
            'message' => $request->message ?? '',
            'message_type' => $messageType,
            'file' => $request->hasFile('file') ? FileService::compressAndUpload($request->file('file'), 'chat') : '',
            'audio' => $request->hasFile('audio') ? FileService::compressAndUpload($request->file('audio'), 'chat') : '',
            'is_read' => 0,
            'status' => 'sent',

            // ako imaš kolone (preporuka)
            'is_auto_reply' => false,
            'auto_reply_type' => null,
        ]);

        // Receiver / userType (tvoj postojeći kod)
        if ($itemOffer->seller_id == $user->id) {
            $receiver_id = $itemOffer->buyer_id;
            $userType = 'Seller';
        } else {
            $receiver_id = $itemOffer->seller_id;
            $userType = 'Buyer';
        }

        $unreadMessagesCount = Chat::where('item_offer_id', $itemOffer->id)
            ->where('is_read', 0)
            ->count();

        // displayMessage (tvoj postojeći kod)
        $displayMessage = $request->message;
        if (empty($displayMessage)) {
            if ($request->hasFile('file')) {
                $mime = $request->file('file')->getMimeType();
                if (str_contains($mime, 'image')) {
                    $displayMessage = '📷 Sent you an image';
                } elseif (str_contains($mime, 'pdf')) {
                    $displayMessage = '📄 Sent you a PDF file';
                } elseif (str_contains($mime, 'word')) {
                    $displayMessage = '📘 Sent you a document';
                } elseif (str_contains($mime, 'text')) {
                    $displayMessage = '📄 Sent you a text file';
                } else {
                    $displayMessage = '📎 Sent you a file';
                }
            } elseif ($request->hasFile('audio')) {
                $displayMessage = '🎤 Sent you an audio message';
            } else {
                $displayMessage = '💬 Sent you a message';
            }
        }

        // FCM payload (tvoj postojeći kod)
        $notificationPayload = $chat->toArray();
        $fcmMsg = [
            ...$notificationPayload,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_profile' => $user->profile,
            'user_type' => $userType,
            'item_id' => $itemOffer->item->id,
            'item_name' => $itemOffer->item->name,
            'item_image' => $itemOffer->item->image,
            'item_price' => $itemOffer->item->price,
            'item_offer_id' => $itemOffer->id,
            'item_offer_amount' => $itemOffer->amount,
            'type' => $notificationPayload['message_type'],
            'message_type_temp' => $notificationPayload['message_type'],
            'unread_count' => $unreadMessagesCount,
        ];
        unset($fcmMsg['message_type']);

        $receiverFCMTokens = UserFcmToken::where('user_id', $receiver_id)->pluck('fcm_token')->toArray();

        // =========================
        // ✅ AUTO-REPLY LOGIKA (PRO)
        // =========================
        $autoReplyChat = null;

        $sellerId = $itemOffer->seller_id;
        $buyerId  = $itemOffer->buyer_id;
        $senderId = $user->id;

        // Auto-reply samo kad buyer piše selleru
        $isBuyerSending = ($senderId == $buyerId && $senderId != $sellerId);

        if ($isBuyerSending) {
            $sellerSettings = \App\Models\SellerSetting::where('user_id', $sellerId)->first();
         
            $membership = \App\Models\UserMembership::where('user_id', $sellerId)
                ->where('status', 'active')
                ->first();
                
         
            // Provjera Pro/Shop statusa - podržava tier_id (int) i tier (string)
            $isPro = false;
            $isShop = false;
            
            if ($membership) {
                // 1. Provjeri tier kao string
                $tier = strtolower($membership->tier ?? $membership->tier_name ?? $membership->plan ?? '');
                
                if (strpos($tier, 'shop') !== false || strpos($tier, 'business') !== false) {
                    $isPro = true;
                    $isShop = true;
                } elseif (strpos($tier, 'pro') !== false || strpos($tier, 'premium') !== false) {
                    $isPro = true;
                }
                
                // 2. Fallback na tier_id ako string nije dao rezultat
                if (!$isPro && !empty($membership->tier_id)) {
                    $tierId = (int) $membership->tier_id;
                    if ($tierId === 3) { // Shop
                        $isPro = true;
                        $isShop = true;
                    } elseif ($tierId === 2) { // Pro
                        $isPro = true;
                    }
                }
                
                // 3. Debug log 
                \Log::info('Auto-reply membership check', [
                    'seller_id' => $sellerId,
                    'tier' => $tier,
                    'tier_id' => $membership->tier_id ?? null,
                    'isPro' => $isPro,
                    'isShop' => $isShop,
                ]);
            }
         
            if ($isPro && $sellerSettings) {
         
                // Vacation mode auto-reply (prioritet)
                if (!empty($sellerSettings->vacation_mode) && !empty($sellerSettings->vacation_message)) {
         
                    $existingVacationReply = Chat::where('item_offer_id', $request->item_offer_id)
                        ->where('sender_id', $sellerId)
                        ->where('is_auto_reply', true)
                        ->where('auto_reply_type', 'vacation')
                        ->where('created_at', '>', now()->subHours(24))
                        ->exists();
         
                    if (!$existingVacationReply) {
                        $autoReplyChat = Chat::create([
                            'sender_id' => $sellerId,
                            'item_offer_id' => $request->item_offer_id,
                            'message' => $sellerSettings->vacation_message,
                            'message_type' => 'text',
                            'file' => '',
                            'audio' => '',
                            'is_read' => 0,
                            'status' => 'sent',
                            'is_auto_reply' => true,
                            'auto_reply_type' => 'vacation',
                        ]);
                        
                    }
                }
                // Standard auto-reply
                elseif (!empty($sellerSettings->auto_reply_enabled) && !empty($sellerSettings->auto_reply_message)) {
         
                    $recentBuyerMessages = Chat::where('item_offer_id', $request->item_offer_id)
                        ->where('sender_id', $buyerId)
                        ->where('created_at', '>', now()->subHours(24))
                        ->count();
         
                    if ($recentBuyerMessages <= 1) {
                        $autoReplyChat = Chat::create([
                            'sender_id' => $sellerId,
                            'item_offer_id' => $request->item_offer_id,
                            'message' => $sellerSettings->auto_reply_message,
                            'message_type' => 'text',
                            'file' => '',
                            'audio' => '',
                            'is_read' => 0,
                            'status' => 'sent',
                            'is_auto_reply' => true,
                            'auto_reply_type' => 'standard',
                        ]);
                        
                    }
                }
                // =====================================
// ✅ UPDATE seller.response_time_avg (minutes)
// =====================================
$sellerIdForAvg = (int) $itemOffer->seller_id;
$buyerIdForAvg  = (int) $itemOffer->buyer_id;

// samo kad SELLER šalje poruku (ne buyer)
if ((int)$user->id === $sellerIdForAvg) {

    // zadnja SELLER poruka prije ove (non-auto) – da ne uhvatimo buyer poruku iz stare runde
    $prevSellerMsg = Chat::where('item_offer_id', $itemOffer->id)
        ->where('sender_id', $sellerIdForAvg)
        ->where(function ($q) {
            $q->whereNull('is_auto_reply')->orWhere('is_auto_reply', false)->orWhere('is_auto_reply', 0);
        })
        ->where('created_at', '<', $chat->created_at)
        ->orderByDesc('created_at')
        ->first();

    // zadnja BUYER poruka prije ovog seller odgovora
    $buyerQuery = Chat::where('item_offer_id', $itemOffer->id)
        ->where('sender_id', $buyerIdForAvg)
        ->where('created_at', '<', $chat->created_at);

    if ($prevSellerMsg) {
        $buyerQuery->where('created_at', '>', $prevSellerMsg->created_at);
    }

    $lastBuyerMsg = $buyerQuery->orderByDesc('created_at')->first();

    if ($lastBuyerMsg) {
        $diffMin = $chat->created_at->diffInMinutes($lastBuyerMsg->created_at);
        $diffMin = max(0, min((int)$diffMin, 10080)); // clamp 0..7 dana

        // EMA 80/20 (stabilniji prosjek)
        $sellerUser = User::find($sellerIdForAvg);
        if ($sellerUser) {
            $old = $sellerUser->response_time_avg;

            $newAvg = $old === null
                ? $diffMin
                : (int) round(((float)$old * 0.8) + ($diffMin * 0.2));

            // update direktno (sigurno)
            User::where('id', $sellerIdForAvg)->update(['response_time_avg' => $newAvg]);
        }
    }
}

            }
        }

        DB::commit();

        // =========================
        // ✅ Broadcast + FCM (tvoj)
        // =========================
        $messageData = [
            'id' => $chat->id,
            'chat_id' => $request->item_offer_id,
            'sender_id' => $chat->sender_id,
            'message' => $chat->message,
            'message_type' => $chat->message_type,
            'file' => $chat->file ? asset('storage/' . $chat->file) : null,
            'audio' => $chat->audio ? asset('storage/' . $chat->audio) : null,
            'created_at' => $chat->created_at->toISOString(),
            'status' => 'sent',
            'is_auto_reply' => (bool) ($chat->is_auto_reply ?? false),
            'auto_reply_type' => $chat->auto_reply_type ?? null,
        ];

        try {
            broadcast(new \App\Events\NewMessage($messageData))->toOthers();
        } catch (\Exception $e) {
            \Log::error('WebSocket broadcast failed: ' . $e->getMessage());
        }

        try {
            event(new UserRealtimeNotification(
                (int) $receiver_id,
                'chat',
                'new_message',
                'Nova poruka',
                $displayMessage,
                [
                    'id' => $chat->id,
                    'type' => 'chat',
                    'chat_id' => $itemOffer->id,
                    'item_offer_id' => $itemOffer->id,
                    'sender_id' => $chat->sender_id,
                    'message' => $chat->message,
                    'message_type' => $chat->message_type,
                    'message_type_temp' => $chat->message_type,
                    'file' => $messageData['file'],
                    'audio' => $messageData['audio'],
                    'created_at' => $messageData['created_at'],
                    'updated_at' => optional($chat->updated_at)->toISOString(),
                    'user_type' => $userType,
                    'unread_count' => $unreadMessagesCount,
                ]
            ));
        } catch (\Exception $e) {
            \Log::error('Realtime user notification failed: ' . $e->getMessage());
        }

        $notification = NotificationService::sendFcmNotification(
            $receiverFCMTokens,
            'Message',
            $displayMessage,
            'chat',
            $fcmMsg
        );

        // =========================
        // ✅ Ako je kreiran auto-reply, pošalji ga buyeru
        // =========================
        $autoReplyDebug = null;

        if ($autoReplyChat) {
            $autoMessageData = [
                'id' => $autoReplyChat->id,
                'chat_id' => $request->item_offer_id,
                'sender_id' => $autoReplyChat->sender_id,
                'message' => $autoReplyChat->message,
                'message_type' => $autoReplyChat->message_type,
                'file' => null,
                'audio' => null,
                'created_at' => $autoReplyChat->created_at->toISOString(),
                'status' => 'sent',
                'is_auto_reply' => true,
                'auto_reply_type' => $autoReplyChat->auto_reply_type,
            ];

            // Broadcast (da buyer dobije poruku)
            try {
                broadcast(new \App\Events\NewMessage($autoMessageData));
            } catch (\Exception $e) {
                \Log::error('WebSocket broadcast auto-reply failed: ' . $e->getMessage());
            }

            // FCM buyeru
            $buyerTokens = UserFcmToken::where('user_id', $buyerId)->pluck('fcm_token')->toArray();

            $sellerUser = \App\Models\User::find($sellerId);
            $autoFcmMsg = [
                ...$autoReplyChat->toArray(),
                'user_id' => $sellerId,
                'user_name' => $sellerUser->name ?? 'Seller',
                'user_profile' => $sellerUser->profile ?? null,
                'user_type' => 'Seller',
                'item_id' => $itemOffer->item->id,
                'item_name' => $itemOffer->item->name,
                'item_image' => $itemOffer->item->image,
                'item_price' => $itemOffer->item->price,
                'item_offer_id' => $itemOffer->id,
                'item_offer_amount' => $itemOffer->amount,
                'type' => 'text',
                'message_type_temp' => 'text',
                'unread_count' => Chat::where('item_offer_id', $itemOffer->id)
                    ->where('sender_id', '!=', $buyerId)
                    ->where('status', '!=', 'seen')
                    ->count(),
            ];

            // ne diramo message_type, ali ako postoji u modelu i smeta, možeš unset kao gore
            $autoReplyDebug = NotificationService::sendFcmNotification(
                $buyerTokens,
                'Message',
                $autoReplyChat->message,
                'chat',
                $autoFcmMsg
            );

            try {
                $buyerUnreadCount = Chat::where('item_offer_id', $itemOffer->id)
                    ->where('sender_id', '!=', $buyerId)
                    ->where('status', '!=', 'seen')
                    ->count();

                event(new UserRealtimeNotification(
                    (int) $buyerId,
                    'chat',
                    'auto_reply',
                    'Nova poruka',
                    $autoReplyChat->message,
                    [
                        'id' => $autoReplyChat->id,
                        'type' => 'chat',
                        'chat_id' => $itemOffer->id,
                        'item_offer_id' => $itemOffer->id,
                        'sender_id' => $autoReplyChat->sender_id,
                        'message' => $autoReplyChat->message,
                        'message_type' => $autoReplyChat->message_type,
                        'message_type_temp' => $autoReplyChat->message_type,
                        'file' => null,
                        'audio' => null,
                        'created_at' => $autoReplyChat->created_at->toISOString(),
                        'updated_at' => optional($autoReplyChat->updated_at)->toISOString(),
                        'user_type' => 'Seller',
                        'unread_count' => $buyerUnreadCount,
                    ]
                ));
            } catch (\Exception $e) {
                \Log::error('Realtime auto-reply notification failed: ' . $e->getMessage());
            }
        }

        // Response (tvoj + dodao auto_reply ako postoji)
        $responseData = $chat->toArray();
        $responseData['status'] = 'sent';
        $responseData['message_type'] = $messageType;

        if ($autoReplyChat) {
            $responseData['auto_reply'] = [
                'id' => $autoReplyChat->id,
                'sender_id' => $autoReplyChat->sender_id,
                'message' => $autoReplyChat->message,
                'message_type' => $autoReplyChat->message_type,
                'created_at' => $autoReplyChat->created_at->toISOString(),
                'status' => 'sent',
                'is_auto_reply' => true,
                'auto_reply_type' => $autoReplyChat->auto_reply_type,
            ];
        } else {
            $responseData['auto_reply'] = null;
        }

        

        ResponseService::successResponse(
            __('Message Fetched Successfully'),
            $responseData,
            ['debug' => $notification, 'auto_reply_debug' => $autoReplyDebug]
        );

    } catch (Throwable $th) {
        DB::rollBack();
        ResponseService::logErrorResponse($th, 'API Controller -> sendMessage');
        ResponseService::errorResponse();
    }
}


public function getChatMessages(Request $request)
{
    $validator = Validator::make($request->all(), [
        'item_offer_id' => 'required',
    ]);
    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }
    try {
        $itemOffer = ItemOffer::owner()->findOrFail($request->item_offer_id);
        $authUserId = Auth::user()->id;
        
        // Dohvati poruke
        $chat = Chat::where('item_offer_id', $itemOffer->id)
            ->orderBy('created_at', 'DESC')
            ->paginate();
        
        // Označi tuđe poruke kao pročitane
        Chat::where('item_offer_id', $itemOffer->id)
            ->where('sender_id', '!=', $authUserId)
            ->where('is_read', 0)
            ->update([
                'is_read' => 1,
                'status' => 'seen'
            ]);
        
        // 🔥 DODANO: Broadcast da su poruke viđene
        $messagesToMark = Chat::where('item_offer_id', $itemOffer->id)
            ->where('sender_id', '!=', $authUserId)
            ->pluck('id');
            
        foreach ($messagesToMark as $messageId) {
            broadcast(new \App\Events\MessageStatusUpdated(
                $messageId,
                $itemOffer->id,
                'seen'
            ))->toOthers();
        }
        
        ResponseService::successResponse(__('Messages Fetched Successfully'), $chat);
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> getChatMessages');
        ResponseService::errorResponse();
    }
}

    public function deleteUser()
    {
        try {
            $user = User::withTrashed()->findOrFail(Auth::user()->id);
            app(UserDeletionService::class)->forceDeleteUser($user);
            ResponseService::successResponse(__('User Deleted Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> deleteUser');
            ResponseService::errorResponse();
        }
    }

    public function inAppPurchase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'purchase_token' => 'required',
            'payment_method' => 'required|in:google,apple',
            'package_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $package = Package::findOrFail($request->package_id);
            $purchasedPackage = UserPurchasedPackage::where(['user_id' => Auth::user()->id, 'package_id' => $request->package_id])->first();
            if (! empty($purchasedPackage)) {
                ResponseService::errorResponse(__('You already have purchased this package'));
            }

            PaymentTransaction::create([
                'user_id' => Auth::user()->id,
                'amount' => $package->final_price,
                'payment_gateway' => $request->payment_method,
                'order_id' => $request->purchase_token,
                'payment_status' => 'success',
            ]);

            UserPurchasedPackage::create([
                'user_id' => Auth::user()->id,
                'package_id' => $request->package_id,
                'start_date' => Carbon::now(),
                'total_limit' => $package->item_limit == 'unlimited' ? null : $package->item_limit,
                'end_date' => $package->duration == 'unlimited' ? null : Carbon::now()->addDays($package->duration),
            ]);
            ResponseService::successResponse(__('Package Purchased Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> inAppPurchase');
            ResponseService::errorResponse();
        }
    }

    public function blockUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'blocked_user_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            BlockUser::create([
                'user_id' => Auth::user()->id,
                'blocked_user_id' => $request->blocked_user_id,
            ]);
            ResponseService::successResponse(__('User Blocked Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> blockUser');
            ResponseService::errorResponse();
        }
    }

    public function unblockUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'blocked_user_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            BlockUser::where([
                'user_id' => Auth::user()->id,
                'blocked_user_id' => $request->blocked_user_id,
            ])->delete();
            ResponseService::successResponse(__('User Unblocked Successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> unblockUser');
            ResponseService::errorResponse();
        }
    }

    public function getBlockedUsers()
    {
        try {
            $blockedUsers = BlockUser::where('user_id', Auth::user()->id)->pluck('blocked_user_id');
            $users = User::whereIn('id', $blockedUsers)->select(['id', 'name', 'profile'])->get();
            ResponseService::successResponse(__('User Unblocked Successfully'), $users);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> unblockUser');
            ResponseService::errorResponse();
        }
    }

    public function getTips()
    {
        try {
            $tips = Tip::select(['id', 'description'])->orderBy('sequence', 'ASC')->with('translations')->get();
            ResponseService::successResponse(__('Tips Fetched Successfully'), $tips);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getTips');
            ResponseService::errorResponse();
        }
    }

    public function getBlog(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'category_id' => 'nullable|integer|exists:categories,id',
                'blog_id' => 'nullable|integer|exists:blogs,id',
                'sort_by' => 'nullable|in:new-to-old,old-to-new,popular',
                'views' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            if ($request->views == 1) {
                if (! empty($request->id)) {
                    Blog::where('id', $request->id)->increment('views');
                } elseif (! empty($request->slug)) {
                    Blog::where('slug', $request->slug)->increment('views');
                } else {
                    return ResponseService::errorResponse(__('ID or Slug is required to increment views'));
                }
            }
            $blogs = Blog::with('translations')->when(! empty($request->id), static function ($q) use ($request) {
                $q->where('id', $request->id);
                Blog::where('id', $request->id);
            })
                ->when(! empty($request->slug), function ($q) use ($request) {
                    $q->where('slug', $request->slug);
                    Blog::where('slug', $request->slug);
                })
                ->when(! empty($request->sort_by), function ($q) use ($request) {
                    if ($request->sort_by === 'new-to-old') {
                        $q->orderByDesc('created_at');
                    } elseif ($request->sort_by === 'old-to-new') {
                        $q->orderBy('created_at');
                    } elseif ($request->sort_by === 'popular') {
                        $q->orderByDesc('views');
                    }
                })
                ->when(! empty($request->tag), function ($q) use ($request) {
                    $q->where(function ($query) use ($request) {
                        $query->where('tags', 'like', '%'.$request->tag.'%')
                            ->orWhereHas('translations', function ($translationQuery) use ($request) {
                                $translationQuery->where('tags', 'like', '%'.$request->tag.'%');
                            });
                    });
                })
                ->paginate();

            $otherBlogs = [];
            if (! empty($request->id) || ! empty($request->slug)) {
                $otherBlogs = Blog::with('translations')
                    ->when(! empty($request->id), function ($q) use ($request) {
                        $q->where('id', '!=', $request->id);
                    })
                    ->when(! empty($request->slug), function ($q) use ($request) {
                        $q->where('slug', '!=', $request->slug);
                    })
                    ->orderByDesc('id')
                    ->limit(3)
                    ->get();
            }

            ResponseService::successResponse(__('Blogs fetched successfully'), $blogs, ['other_blogs' => $otherBlogs]);
        } catch (Throwable $th) {
            // Log and handle exceptions
            ResponseService::logErrorResponse($th, 'API Controller -> getBlog');
            ResponseService::errorResponse(__('Failed to fetch blogs'));
        }
    }

    public function getCountries(Request $request)
    {
        try {
            $searchQuery = $request->search ?? '';
            $countries = Country::withCount('states')
                ->where(function ($query) use ($searchQuery) {
                    $query->where('name', 'LIKE', "%{$searchQuery}%")
                        ->orWhereHas('translations', function ($q) use ($searchQuery) {
                            $q->where('name', 'LIKE', "%{$searchQuery}%");
                        });
                })
                ->with(['translations.language:id,code'])
                ->orderBy('name', 'ASC')
                ->paginate();

            // Map translations to include `language_code`
            $countries->getCollection()->transform(function ($country) {
                if ($country->translations instanceof \Illuminate\Support\Collection) {
                    $country->translations = $country->translations->map(function ($translation) {
                        return [
                            'id' => $translation->id,
                            'country_id' => $translation->country_id,
                            'language_id' => $translation->language_id,
                            'name' => $translation->name,
                            'language_code' => optional($translation->language)->code,
                        ];
                    });
                } else {
                    // if somehow it's not a collection, fallback
                    $country->translations = [];
                }

                return $country;
            });

            ResponseService::successResponse(__('Countries Fetched Successfully'), $countries);

        } catch (Throwable $th) {
            // Log and handle any exceptions
            ResponseService::logErrorResponse($th, 'API Controller -> getCountries');
            ResponseService::errorResponse(__('Failed to fetch countries'));
        }
    }

    public function getStates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'nullable|integer',
            'search' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $searchQuery = $request->search ?? '';
            $statesQuery = State::withCount('cities')
                ->where('name', 'LIKE', "%{$searchQuery}%")
                ->orderBy('name', 'ASC');

            if (isset($request->country_id)) {
                $statesQuery->where('country_id', $request->country_id);
            }

            $states = $statesQuery->paginate();

            ResponseService::successResponse(__('States Fetched Successfully'), $states);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller->getStates');
            ResponseService::errorResponse(__('Failed to fetch states'));
        }
    }

    public function getCities(Request $request)
    {
        try {
            // Validate
            $validator = Validator::make($request->all(), [
                'state_id' => 'nullable|integer',
                'search' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $searchQuery = $request->search ?? '';

            // Base query
            $citiesQuery = City::with('translations')
                ->withCount('areas')
                ->orderBy('cities.name', 'ASC'); // force main table for sorting

            // Search filter: main name OR translated name
            if ($searchQuery !== '') {
                $citiesQuery->where(function ($q) use ($searchQuery) {
                    $q->where('cities.name', 'LIKE', "%{$searchQuery}%")
                        ->orWhereHas('translations', function ($t) use ($searchQuery) {
                            $t->where('name', 'LIKE', "%{$searchQuery}%");
                        });
                });
            }

            // State filter
            if ($request->filled('state_id')) {
                $citiesQuery->where('cities.state_id', $request->state_id);
            }

            // Pagination
            $cities = $citiesQuery->paginate();

            return ResponseService::successResponse(__('Cities Fetched Successfully'), $cities);

        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller->getCities');

            return ResponseService::errorResponse(__('Failed to fetch cities'));
        }
    }

    public function getAreas(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city_id' => 'nullable|integer',
            'search' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $searchQuery = $request->search ?? '';
            $data = Area::with('translations')->search($searchQuery)->orderBy('name', 'ASC');
            if (isset($request->city_id)) {
                $data->where('city_id', $request->city_id);
            }

            $data = $data->paginate();
            ResponseService::successResponse(__('Area fetched Successfully'), $data);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getAreas');
            ResponseService::errorResponse();
        }
    }

    public function getFaqs()
    {
        try {
            $faqs = Faq::with('translations')->get();
            ResponseService::successResponse(__('FAQ Data fetched Successfully'), $faqs);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getFaqs');
            ResponseService::errorResponse(__('Failed to fetch Faqs'));
        }
    }

    public function getAllBlogTags()
    {
        try {
            $languageCode = request()->header('Content-Language') ?? app()->getLocale();

            $language = Language::select(['id', 'code', 'name'])
                ->where('code', $languageCode)
                ->first();

            if (! $language) {
                return ResponseService::errorResponse('Invalid language code');
            }

            $tagsMap = [];

            Blog::with(['translations' => function ($q) use ($language) {
                $q->where('language_id', $language->id);
            }])->chunk(100, function ($blogs) use (&$tagsMap) {
                foreach ($blogs as $blog) {
                    $defaultTagsRaw = $blog->tags;
                    $defaultTags = [];
                    if (! empty($defaultTagsRaw)) {
                        if (is_string($defaultTagsRaw)) {
                            $decoded = json_decode($defaultTagsRaw, true);
                            if (json_last_error() === JSON_ERROR_NONE && ! empty($decoded)) {
                                $defaultTags = is_array($decoded) ? $decoded : [$decoded];
                            } else {
                                $defaultTags = array_map('trim', explode(',', $defaultTagsRaw));
                            }
                        } elseif (is_array($defaultTagsRaw)) {
                            $defaultTags = $defaultTagsRaw;
                        }
                    }
                    $translatedTagsRaw = $blog->translations->first()?->tags;
                    $translatedTags = [];
                    if (! empty($translatedTagsRaw)) {
                        if (is_string($translatedTagsRaw)) {
                            $decoded = json_decode($translatedTagsRaw, true);
                            if (json_last_error() === JSON_ERROR_NONE && ! empty($decoded)) {
                                $translatedTags = is_array($decoded) ? $decoded : [$decoded];
                            } else {
                                $translatedTags = array_map('trim', explode(',', $translatedTagsRaw));
                            }
                        } elseif (is_array($translatedTagsRaw)) {
                            $translatedTags = $translatedTagsRaw;
                        }
                    }
                    foreach ($defaultTags as $index => $defaultTag) {
                        $translated = $translatedTags[$index] ?? $defaultTag;
                        $tagsMap[$defaultTag] = $translated;
                    }
                }
            });
            $result = [];
            foreach ($tagsMap as $defaultTag => $translatedTag) {
                $result[] = [
                    'label' => $translatedTag,
                    'value' => $defaultTag,
                ];
            }

            ResponseService::successResponse('Blog Tags Retrieved Successfully', array_values($result));
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getAllBlogTags');

            return ResponseService::errorResponse('Failed to fetch Tags');
        }
    }

    public function storeContactUs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'subject' => 'required|string|max:190',
            'message' => 'required|string|max:5000',
            'phone' => 'nullable|string|max:40',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $payload = [
                'name' => trim((string) $request->input('name')),
                'email' => trim((string) $request->input('email')),
                'subject' => trim((string) $request->input('subject')),
                'message' => trim((string) $request->input('message')),
                'phone' => trim((string) $request->input('phone', '')),
            ];

            $contact = ContactUs::create($payload);

            $deliveryResult = $this->deliverContactFormEmail($payload);

            if ($deliveryResult['sent'] === true) {
                ResponseService::successResponse(__('Poruka je uspješno poslana.'));
            }

            Log::warning('Contact form saved but email delivery failed', [
                'contact_id' => $contact->id,
                'mail_to' => $deliveryResult['mail_to'],
                'attempted_mailers' => $deliveryResult['attempted_mailers'],
                'last_error' => $deliveryResult['last_error'],
            ]);

            ResponseService::warningResponse(
                __('Poruka je sačuvana i vidljiva podršci u inboxu, ali e-mail obavijest trenutno nije isporučena.'),
                [
                    'saved' => true,
                    'contact_id' => $contact->id,
                    'email_sent' => false,
                ],
                config('constants.RESPONSE_CODE.SUCCESS'),
                200
            );
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> storeContactUs');
            ResponseService::errorResponse();
        }
    }

    private function deliverContactFormEmail(array $payload): array
    {
        $mailToRaw = (string) config('mail.contact_to', 'info@lmx.ba');
        $mailTo = filter_var($mailToRaw, FILTER_VALIDATE_EMAIL) ? $mailToRaw : 'info@lmx.ba';

        $fromAddressRaw = (string) (config('mail.from.address') ?: $mailTo);
        $fromAddress = filter_var($fromAddressRaw, FILTER_VALIDATE_EMAIL) ? $fromAddressRaw : $mailTo;
        $fromName = (string) (config('mail.from.name') ?: config('app.name', 'LMX'));
        $replyTo = filter_var($payload['email'] ?? null, FILTER_VALIDATE_EMAIL) ? $payload['email'] : null;
        $phoneLine = !empty($payload['phone']) ? "\nTelefon: {$payload['phone']}" : '';

        $emailSubject = sprintf('[LMX Kontakt] %s', (string) ($payload['subject'] ?? 'Kontakt forma'));
        $emailBody = "Nova poruka sa kontakt forme:\n\n"
            ."Ime: ".($payload['name'] ?? '-')."\n"
            ."E-mail: ".($payload['email'] ?? '-')
            .$phoneLine
            ."\nNaslov: ".($payload['subject'] ?? '-') . "\n\n"
            ."Poruka:\n".($payload['message'] ?? '-');

        $configuredMailers = config('mail.contact_mailers', []);
        if (!is_array($configuredMailers) || empty($configuredMailers)) {
            $configuredMailers = [
                (string) config('mail.default', 'smtp'),
                'failover',
            ];
        }
        $mailers = array_values(array_unique(array_filter($configuredMailers)));

        $attempted = [];
        $lastError = null;

        foreach ($mailers as $mailer) {
            try {
                Mail::mailer($mailer)->raw($emailBody, function ($mail) use ($mailTo, $fromAddress, $fromName, $replyTo, $payload, $emailSubject) {
                    $mail->to($mailTo)
                        ->from($fromAddress, $fromName)
                        ->subject($emailSubject);

                    if ($replyTo) {
                        $mail->replyTo($replyTo, (string) ($payload['name'] ?? 'Kontakt'));
                    }
                });

                return [
                    'sent' => true,
                    'mailer' => $mailer,
                    'mail_to' => $mailTo,
                    'attempted_mailers' => $attempted,
                    'last_error' => null,
                ];
            } catch (Throwable $mailException) {
                $attempted[] = $mailer;
                $lastError = $mailException->getMessage();
            }
        }

        return [
            'sent' => false,
            'mailer' => null,
            'mail_to' => $mailTo,
            'attempted_mailers' => $attempted,
            'last_error' => $lastError,
        ];
    }

    public function addItemReview(Request $request)
{
    $validator = Validator::make($request->all(), [
        'review' => 'nullable|string|max:1000',
        'ratings' => 'required|numeric|between:1,5',
        'item_id' => 'required|exists:items,id',
        'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // max 5MB po slici
    ]);
 
    if ($validator->fails()) {
        return ResponseService::validationError($validator->errors()->first());
    }
 
    try {
        $item = Item::with('user')->findOrFail($request->item_id);
 
        // Provjera da li je korisnik vlasnik itema
        if ($item->user_id === Auth::id()) {
            return ResponseService::errorResponse(__('You cannot review your own item.'));
        }
 
        // Provjera da li je korisnik kupio item
        if ($item->sold_to !== Auth::id()) {
            return ResponseService::errorResponse(__('You can only review items that you have purchased.'));
        }
 
        // Provjera statusa itema
        if ($item->status !== 'sold out') {
            return ResponseService::errorResponse(__("The item must be marked as 'sold out' before you can review it."));
        }
 
        // Provjera da li je već ostavljena recenzija
        $existingReview = SellerRating::where('item_id', $request->item_id)
            ->where('buyer_id', Auth::id())
            ->first();
 
        if ($existingReview) {
            return ResponseService::errorResponse(__('You have already reviewed this item.'));
        }

        if ($request->has('scheduled_at') && $request->scheduled_at) {
            $data['scheduled_at'] = $request->scheduled_at;
            $data['status'] = 'scheduled';
        }
 
        // Kreiraj recenziju
        $review = SellerRating::create([
            'item_id' => $request->item_id,
            'buyer_id' => Auth::id(),
            'seller_id' => $item->user_id,
            'ratings' => $request->ratings,
            'review' => $request->review ?? '',
        ]);
 
        // Upload slika ako postoje
        if ($request->hasFile('images')) {
            $uploadedImages = [];
            
            foreach ($request->file('images') as $image) {
                // Generiši unique ime
                $filename = 'review_' . $review->id . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // Sačuvaj sliku
                $path = $image->storeAs('reviews', $filename, 'public');
                
                // Dodaj URL u array
                $uploadedImages[] = asset('storage/' . $path);
            }
            
            // Sačuvaj URLs u bazu
            $review->images = $uploadedImages;
            $review->save();
        }
 
        // Učitaj relacije za response
        $review->load(['buyer:id,name,profile', 'item:id,name,image']);
 
        // Pošalji notifikaciju prodavaču
        $userTokens = UserFcmToken::where('user_id', $item->user_id)
            ->pluck('fcm_token')
            ->toArray();
 
        if (!empty($userTokens)) {
            NotificationService::sendFcmNotification(
                $userTokens,
                __('New Review'),
                __('A new review has been added to your advertisement: ') . $item->name,
                'item-review',
                ['item_id' => $item->id]
            );
        }
 
        return ResponseService::successResponse(__('Your review has been submitted successfully.'), $review);
 
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> addItemReview');
        return ResponseService::errorResponse(__('Something went wrong. Please try again.'));
    }
}

public function getSeller(Request $request)
{
    $validator = Validator::make($request->all(), [
        'id' => 'required|integer',
        'page' => 'nullable|integer',
    ]);
 
    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }
 
    try {
        $sellerId = $request->id;
        $page = $request->page ?? 1;
        $perPage = 10;
 
        // Dohvati seller-a
        $seller = User::find($sellerId);
 
        if (!$seller) {
            ResponseService::errorResponse(__('User not found'), null, '', 103);
        }
 
        // Dohvati broj aktivnih oglasa
        $liveAdsCount = Item::where('user_id', $sellerId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->where(function($q) {
                $q->whereNull('expiry_date')
                  ->orWhere('expiry_date', '>=', Carbon::now());
            })
            ->count();
 
        // Dohvati broj prodanih oglasa
        $soldAdsCount = Item::where('user_id', $sellerId)
            ->where('status', 'sold out')
            ->whereNull('deleted_at')
            ->count();
 
        $seller->live_ads_count = $liveAdsCount;
        $seller->sold_ads_count = $soldAdsCount;
 
        // Dohvati recenzije s paginacijom - KORISTI SellerRating
        $ratings = \App\Models\SellerRating::where('seller_id', $sellerId)
            ->with(['buyer:id,name,profile', 'item:id,name,image,slug'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
 
        // Izračunaj prosječnu ocjenu
        $averageRating = \App\Models\SellerRating::where('seller_id', $sellerId)->avg('ratings') ?? 0;
        $reviewsCount = \App\Models\SellerRating::where('seller_id', $sellerId)->count();
 
        $seller->average_rating = round($averageRating, 1);
        $seller->reviews_count = $reviewsCount;
 
        // =====================================================
        // SELLER SETTINGS I MEMBERSHIP
        // =====================================================
        $sellerSettings = null;
        $isPro = false;
        $isShop = false;
        $membershipData = null;
 
        // Dohvati seller settings
        $sellerSettings = \App\Models\SellerSetting::where('user_id', $sellerId)->first();
 
        // Dohvati membership
        $membership = \App\Models\UserMembership::where('user_id', $sellerId)
            ->where('status', 'active')
            ->first();
 
        if ($membership) {
            // Provjeri tier kao string
            $tier = strtolower($membership->tier ?? $membership->tier_name ?? $membership->plan ?? '');
            
            if (strpos($tier, 'shop') !== false || strpos($tier, 'business') !== false) {
                $isPro = true;
                $isShop = true;
            } elseif (strpos($tier, 'pro') !== false || strpos($tier, 'premium') !== false) {
                $isPro = true;
                $isShop = false;
            }
            
            // Fallback na tier_id
            if (!$isPro && !empty($membership->tier_id)) {
                $tierId = (int) $membership->tier_id;
                if ($tierId === 3) {
                    $isPro = true;
                    $isShop = true;
                } elseif ($tierId === 2) {
                    $isPro = true;
                    $isShop = false;
                }
            }
 
            $membershipData = [
                'tier' => $membership->tier ?? $membership->tier_name ?? null,
                'tier_id' => $membership->tier_id ?? null,
                'status' => $membership->status ?? null,
                'expires_at' => $membership->expires_at ?? null,
            ];
        }
        // =====================================================

        if ($seller->response_time_avg === null) {
            $avg = $this->calculateAverageResponseMinutes((int)$sellerId);
            if ($avg !== null) {
                $seller->response_time_avg = $avg;
                User::where('id', $sellerId)->whereNull('response_time_avg')->update(['response_time_avg' => $avg]);
            }
        }
        
 
        $responseData = [
            'seller' => $seller,
            'ratings' => $ratings,
            'seller_settings' => $sellerSettings,
            'is_pro' => $isPro,
            'is_shop' => $isShop,
            'membership' => $membershipData,
        ];
 
        ResponseService::successResponse(__('Data fetched successfully'), $responseData);
 
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> getSeller');
        ResponseService::errorResponse();
    }
}

private function calculateAverageResponseTime(int $sellerId): ?string
{
    // Uzmi zadnjih 50 offera za sellera (da ne bude preskupo)
    $offers = \App\Models\ItemOffer::where('seller_id', $sellerId)
        ->select('id', 'seller_id', 'buyer_id')
        ->orderByDesc('id')
        ->limit(50)
        ->get();

    if ($offers->isEmpty()) {
        return null;
    }

    $totalMinutes = 0;
    $responseCount = 0;

    foreach ($offers as $offer) {

        // Nađi zadnju poruku od BUYER-a u ovom offeru
        $buyerMsg = \App\Models\Chat::where('item_offer_id', $offer->id)
            ->where('sender_id', $offer->buyer_id)
            ->whereNotNull('created_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$buyerMsg) {
            continue;
        }

        // Nađi prvi SELLER odgovor poslije te buyer poruke
        $sellerReply = \App\Models\Chat::where('item_offer_id', $offer->id)
            ->where('sender_id', $offer->seller_id)
            ->where('created_at', '>', $buyerMsg->created_at)
            ->orderBy('created_at', 'asc')
            ->first();

        if ($sellerReply) {
            $minutes = $sellerReply->created_at->diffInMinutes($buyerMsg->created_at);
            $totalMinutes += $minutes;
            $responseCount++;
        }
    }

    if ($responseCount === 0) {
        return null;
    }

    $avgMinutes = $totalMinutes / $responseCount;

    // Vrati kategoriju (tvoj originalni mapping)
    if ($avgMinutes < 30) return 'instant';
    if ($avgMinutes < 180) return 'few_hours';
    if ($avgMinutes < 1440) return 'same_day';
    return 'few_days';
}

private function calculateAverageResponseMinutes(int $sellerId): ?int
{
    $offers = ItemOffer::where('seller_id', $sellerId)
        ->select('id', 'seller_id', 'buyer_id')
        ->orderByDesc('id')
        ->limit(50)
        ->get();

    if ($offers->isEmpty()) return null;

    $total = 0;
    $count = 0;

    foreach ($offers as $offer) {
        $buyerMsg = Chat::where('item_offer_id', $offer->id)
            ->where('sender_id', $offer->buyer_id)
            ->orderByDesc('created_at')
            ->first();

        if (!$buyerMsg) continue;

        $sellerReply = Chat::where('item_offer_id', $offer->id)
            ->where('sender_id', $offer->seller_id)
            ->where('created_at', '>', $buyerMsg->created_at)
            ->where(function ($q) {
                $q->whereNull('is_auto_reply')->orWhere('is_auto_reply', false)->orWhere('is_auto_reply', 0);
            })
            ->orderBy('created_at', 'asc')
            ->first();

        if ($sellerReply) {
            $mins = $sellerReply->created_at->diffInMinutes($buyerMsg->created_at);
            $mins = max(0, min((int)$mins, 10080));
            $total += $mins;
            $count++;
        }
    }

    if ($count === 0) return null;

    return (int) round($total / $count);
}



    public function renewItem(Request $request)
{
    try {
        $free_ad_listing = Setting::where('name', 'free_ad_listing')->value('value') ?? 0;

        $rules = [
            'item_id' => 'nullable|exists:items,id',
            'item_ids' => 'nullable|string',
            'package_id' => 'nullable|exists:packages,id',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $itemIds = [];

        if ($request->filled('item_id')) {
            $itemIds[] = $request->item_id;
        }

        if ($request->filled('item_ids')) {
            $ids = explode(',', $request->item_ids);
            $ids = array_map('trim', $ids);
            $ids = array_filter($ids, 'strlen');
            $itemIds = array_merge($itemIds, $ids);
        }
        $itemIds = array_values(array_unique($itemIds));

        if (empty($itemIds)) {
            return ResponseService::validationError(__('Please provide item_id or item_ids'));
        }

        $user = Auth::user();
        $package = null;
        $userPackage = null;

        if ($request->filled('package_id')) {
            $package = Package::where('id', $request->package_id)->firstOrFail();
            $userPackage = UserPurchasedPackage::onlyActive()
                ->where([
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                ])->first();
            if (! $userPackage) {
                return ResponseService::errorResponse(__('You have not purchased this package'));
            }
        }

        $currentDate = Carbon::now();
        $hasLastRenewedColumn = Schema::hasColumn('items', 'last_renewed_at');
        $renewCooldownDays = 15;
        $results = [];

        foreach ($itemIds as $itemId) {
            $item = Item::where('id', $itemId)
                ->where('user_id', $user->id)
                ->first();

            if (! $item) {
                $results[$itemId] = [
                    'status' => 'failed',
                    'message' => __('Item not found or you are not the owner'),
                ];
                continue;
            }

            $rawStatus = $item->getAttributes()['status'];
            $expiryDate = $item->expiry_date ? Carbon::parse($item->expiry_date) : null;
            $isExpired = $expiryDate !== null && $expiryDate->lte($currentDate);

            // Active (non-expired) renew = bump position every 15 days for non-featured ads.
            if (! $isExpired) {
                $isFeatured = FeaturedItems::onlyActive()
                    ->where('item_id', $item->id)
                    ->exists();

                if ($isFeatured) {
                    $results[$itemId] = [
                        'status' => 'failed',
                        'message' => __('Izdvojeni oglas je već prioritetan. Obnova pozicije je dostupna samo za ne-izdvojene oglase.'),
                    ];
                    continue;
                }

                $lastRenewedAt = $hasLastRenewedColumn
                    ? ($item->last_renewed_at ?? $item->created_at)
                    : ($item->updated_at ?? $item->created_at);
                $nextAllowedAt = Carbon::parse($lastRenewedAt)->addDays($renewCooldownDays);

                if ($currentDate->lt($nextAllowedAt)) {
                    $results[$itemId] = [
                        'status' => 'failed',
                        'message' => __('Oglas možeš obnoviti svakih :days dana. Sljedeća obnova: :date', [
                            'days' => $renewCooldownDays,
                            'date' => $nextAllowedAt->format('d.m.Y H:i'),
                        ]),
                    ];
                    continue;
                }

                if ($hasLastRenewedColumn) {
                    $item->last_renewed_at = $currentDate;
                }
                $item->save();

                $results[$itemId] = [
                    'status' => 'success',
                    'message' => __('Oglas je podignut među ne-istaknute oglase.'),
                    'item' => $item->fresh(),
                    'renewal_type' => 'position',
                    'next_renewal_at' => $currentDate->copy()->addDays($renewCooldownDays)->toDateTimeString(),
                ];
                continue;
            }

            // Expired renew = extend expiry period.
            if ($free_ad_listing == 0 && ! $package) {
                $results[$itemId] = [
                    'status' => 'failed',
                    'message' => __('Odaberite paket za obnovu isteklog oglasa.'),
                ];
                continue;
            }

            if ($package) {
                if ($userPackage->total_limit !== null && (int) $userPackage->used_limit >= (int) $userPackage->total_limit) {
                    $results[$itemId] = [
                        'status' => 'failed',
                        'message' => __('Dostigli ste limit odabranog paketa za obnovu oglasa.'),
                    ];
                    continue;
                }

                if ($package->duration === 'unlimited') {
                    $item->expiry_date = null;
                } else {
                    $item->expiry_date = $currentDate->copy()->addDays((int) $package->duration);
                }

                $userPackage->used_limit++;
                $userPackage->save();
            } else {
                $item->expiry_date = $currentDate->copy()->addDays(30);
            }

            if ($hasLastRenewedColumn) {
                $item->last_renewed_at = $currentDate;
            }
            $item->status = $rawStatus;
            $item->save();

            $results[$itemId] = [
                'status' => 'success',
                'message' => __('Advertisement renewed successfully'),
                'item' => $item->fresh(),
                'renewal_type' => 'expiry',
            ];
        }

        if (count($itemIds) === 1) {
            $itemId = $itemIds[0];
            if ($results[$itemId]['status'] === 'success') {
                return ResponseService::successResponse(
                    $results[$itemId]['message'] ?? __('Advertisement renewed successfully'),
                    $results[$itemId]['item']
                );
            }

            return ResponseService::errorResponse($results[$itemId]['message']);
        }

        return ResponseService::successResponse(__('Items processed successfully'), $results);
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> renewItem');

        return ResponseService::errorResponse();
    }
}

public function getMyReview(Request $request)
{
    try {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        
        // 🔥 DEBUG - dodaj ovo
        $authId = Auth::id();
        $authUser = Auth::user();
        
        \Log::info('getMyReview DEBUG', [
            'auth_id' => $authId,
            'auth_user_email' => $authUser?->email,
            'auth_check' => Auth::check(),
            'reviews_count' => SellerRating::where('seller_id', $authId)->count(),
        ]);
        
        // Ako nema auth, vrati grešku
        if (!$authId) {
            return ResponseService::errorResponse('User not authenticated');
        }
 
        $ratings = SellerRating::where('seller_id', $authId)
            ->with(['buyer:id,name,profile', 'item:id,name,image'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
 
        $averageRating = SellerRating::where('seller_id', $authId)->avg('ratings');
 
        $data = [
            'average_rating' => round($averageRating ?? 0, 2),
            'ratings' => $ratings,
        ];
 
        return ResponseService::successResponse(__('Reviews fetched successfully'), $data);
 
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> getMyReview');
        return ResponseService::errorResponse();
    }
}

    public function addReviewReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'report_reason' => 'required|string',
            'seller_review_id' => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $ratings = SellerRating::where('seller_id', Auth::user()->id)->findOrFail($request->seller_review_id);
            $ratings->update([
                'report_status' => 'reported',
                'report_reason' => $request->report_reason,
            ]);

            ResponseService::successResponse(__('Your report has been submitted successfully.'), $ratings);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> addReviewReport');
            ResponseService::errorResponse();
        }
    }

    public function getVerificationFields()
    {
        try {
            $fields = VerificationField::all();
            ResponseService::successResponse(__('Verification Field Fetched Successfully'), $fields);
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ResponseService::logErrorResponse($th, 'API Controller -> getVerificationFields');
            ResponseService::errorResponse();
        }
    }

    public function sendVerificationRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'verification_field' => 'sometimes|array',
            'verification_field.*' => 'sometimes',
            'verification_field_files' => 'nullable|array',
            'verification_field_files.*' => 'nullable|file|mimes:jpeg,png,jpg,pdf,doc|max:7168',
            'verification_field_translations' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (empty($user)) {
            ResponseService::unauthorizedResponse(__('Unauthorized'));
        }

        try {
            DB::beginTransaction();

            $verificationRequest = VerificationRequest::firstOrCreate(
                ['user_id' => $user->id],
                ['status' => 'pending']
            );
            $verificationRequest->status = 'pending';
            $verificationRequest->rejection_reason = null;
            $verificationRequest->save();

            $validFieldIds = VerificationField::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $validFieldIdMap = array_fill_keys($validFieldIds, true);

            $baseFieldIdsToKeep = [];

            $verificationFields = $request->input('verification_field', []);
            if (is_array($verificationFields)) {
                foreach ($verificationFields as $fieldId => $value) {
                    $fieldId = (int) $fieldId;
                    if ($fieldId <= 0 || !isset($validFieldIdMap[$fieldId])) {
                        continue;
                    }

                    $baseFieldIdsToKeep[] = $fieldId;
                    $normalizedValue = is_array($value)
                        ? implode(',', array_values(array_filter(array_map(
                            static fn ($entry) => trim((string) $entry),
                            $value
                        ), static fn ($entry) => $entry !== '')))
                        : trim((string) $value);

                    VerificationFieldValue::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'verification_field_id' => $fieldId,
                            'verification_request_id' => $verificationRequest->id,
                            'language_id' => null,
                        ],
                        ['value' => $normalizedValue]
                    );
                }
            }

            $verificationFiles = $request->file('verification_field_files', []);
            if (is_array($verificationFiles)) {
                foreach ($verificationFiles as $fieldId => $file) {
                    $fieldId = (int) $fieldId;
                    if ($fieldId <= 0 || !isset($validFieldIdMap[$fieldId]) || empty($file)) {
                        continue;
                    }

                    $baseFieldIdsToKeep[] = $fieldId;
                    $uploadedPath = FileService::upload($file, 'verification_field_files');

                    VerificationFieldValue::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'verification_field_id' => $fieldId,
                            'verification_request_id' => $verificationRequest->id,
                            'language_id' => null,
                        ],
                        ['value' => (string) $uploadedPath]
                    );
                }
            }

            $baseFieldIdsToKeep = array_values(array_unique($baseFieldIdsToKeep));
            $baseValueQuery = VerificationFieldValue::query()
                ->where('user_id', $user->id)
                ->where('verification_request_id', $verificationRequest->id)
                ->whereNull('language_id');

            if (!empty($baseFieldIdsToKeep)) {
                $baseValueQuery->whereNotIn('verification_field_id', $baseFieldIdsToKeep)->delete();
            } else {
                $baseValueQuery->delete();
            }

            if ($request->has('verification_field_translations')) {
                $translationPayload = $request->input('verification_field_translations');
                if (!is_array($translationPayload)) {
                    $translationPayload = json_decode((string) $translationPayload, true, 512, JSON_THROW_ON_ERROR);
                }
                if (!is_array($translationPayload)) {
                    $translationPayload = [];
                }

                $translationKeysToKeep = [];

                foreach ($translationPayload as $languageId => $fieldsById) {
                    $languageId = (int) $languageId;
                    if ($languageId <= 0 || !is_array($fieldsById)) {
                        continue;
                    }

                    foreach ($fieldsById as $fieldId => $translatedValue) {
                        $fieldId = (int) $fieldId;
                        if ($fieldId <= 0 || !isset($validFieldIdMap[$fieldId])) {
                            continue;
                        }

                        $normalizedTranslatedValue = is_array($translatedValue)
                            ? implode(',', array_values(array_filter(array_map(
                                static fn ($entry) => trim((string) $entry),
                                $translatedValue
                            ), static fn ($entry) => $entry !== '')))
                            : trim((string) $translatedValue);

                        VerificationFieldValue::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'verification_field_id' => $fieldId,
                                'verification_request_id' => $verificationRequest->id,
                                'language_id' => $languageId,
                            ],
                            ['value' => $normalizedTranslatedValue]
                        );

                        $translationKeysToKeep["{$languageId}:{$fieldId}"] = true;
                    }
                }

                $existingTranslationRows = VerificationFieldValue::query()
                    ->where('user_id', $user->id)
                    ->where('verification_request_id', $verificationRequest->id)
                    ->whereNotNull('language_id')
                    ->get(['id', 'language_id', 'verification_field_id']);

                foreach ($existingTranslationRows as $row) {
                    $rowKey = ((int) $row->language_id) . ':' . ((int) $row->verification_field_id);
                    if (!isset($translationKeysToKeep[$rowKey])) {
                        $row->delete();
                    }
                }
            }

            DB::commit();

            ResponseService::successResponse(__('Verification request submitted successfully.'));
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ResponseService::logErrorResponse($th, 'API Controller -> sendVerificationRequest');
            ResponseService::errorResponse();
        }
    }

    public function getVerificationRequest(Request $request)
    {
        try {
            $verificationRequest = VerificationRequest::with([
                'verification_field_values.verification_field.translations',
            ])->owner()->first();

            if (empty($verificationRequest)) {
                ResponseService::successResponse(__('Verification request fetched successfully.'), [
                    'status' => 'not applied',
                    'rejection_reason' => '',
                    'verification_fields' => [],
                ]);
            }

            $response = $verificationRequest->toArray();
            $response['verification_fields'] = [];

            // Get current language for translation
            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLanguage = Language::where('code', $contentLangCode)->first();
            $currentLangId = $currentLanguage->id ?? 1;

            $groupedFieldValues = $verificationRequest->verification_field_values
                ->filter(static fn ($row) => !empty($row?->verification_field))
                ->groupBy('verification_field_id');

            foreach ($groupedFieldValues as $fieldRows) {
                $verificationFieldValue = $fieldRows->firstWhere('language_id', $currentLangId);
                if (empty($verificationFieldValue)) {
                    $verificationFieldValue = $fieldRows->first(static fn ($row) => empty($row->language_id));
                }
                if (empty($verificationFieldValue)) {
                    $verificationFieldValue = $fieldRows->first();
                }
                if (empty($verificationFieldValue) || empty($verificationFieldValue->verification_field)) {
                    continue;
                }

                $field = $verificationFieldValue->verification_field;
                $tempRow = $field->toArray();
                $normalizedValue = $this->normalizeVerificationFieldRawValue(
                    $verificationFieldValue->value,
                    $field->type ?? null
                );

                $allPossibleValues = is_array($field->values) ? $field->values : [];
                $translatedValues = $allPossibleValues;
                if (!empty($field->translations)) {
                    $translation = collect($field->translations)->firstWhere('language_id', $currentLangId);
                    if (!empty($translation?->value) && is_array($translation->value)) {
                        $translatedValues = $translation->value;
                    }
                }

                $tempRow['value'] = $normalizedValue;
                $tempRow['language_id'] = $verificationFieldValue->language_id;
                $tempRow['translated_selected_values'] = $this->mapVerificationSelectedValues(
                    $normalizedValue,
                    $allPossibleValues,
                    $translatedValues,
                    $field->type ?? null
                );

                $fieldValuePayload = $verificationFieldValue->toArray();
                unset($fieldValuePayload['verification_field']);
                $fieldValuePayload['value'] = $normalizedValue;
                $tempRow['verification_field_value'] = $fieldValuePayload;

                $response['verification_fields'][] = $tempRow;
            }

            ResponseService::successResponse(__('Verification request fetched successfully.'), $response);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getVerificationRequest');
            ResponseService::errorResponse();
        }
    }

    private function normalizeVerificationFieldRawValue($rawValue, ?string $fieldType = null): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }

        if ($fieldType === 'fileinput') {
            if (is_array($rawValue)) {
                return array_values(array_filter(array_map(
                    fn ($entry) => $this->normalizeVerificationFileValue($entry),
                    $rawValue
                )));
            }

            $fileValue = $this->normalizeVerificationFileValue($rawValue);
            return $fileValue ? [$fileValue] : [];
        }

        if (is_array($rawValue)) {
            return array_values(array_filter(array_map(
                static fn ($entry) => trim((string) $entry),
                $rawValue
            ), static fn ($entry) => $entry !== ''));
        }

        if (is_string($rawValue)) {
            $trimmed = trim($rawValue);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter(array_map(
                    static fn ($entry) => trim((string) $entry),
                    $decoded
                ), static fn ($entry) => $entry !== ''));
            }

            if (str_contains($trimmed, ',')) {
                return array_values(array_filter(array_map(
                    static fn ($entry) => trim((string) $entry),
                    explode(',', $trimmed)
                ), static fn ($entry) => $entry !== ''));
            }

            return [$trimmed];
        }

        return [trim((string) $rawValue)];
    }

    private function normalizeVerificationFileValue($value): ?string
    {
        $rawValue = trim((string) $value);
        if ($rawValue === '') {
            return null;
        }

        if (str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://')) {
            return $rawValue;
        }

        return url(Storage::url($rawValue));
    }

    private function mapVerificationSelectedValues(
        array $selectedRawValues,
        array $allPossibleValues,
        array $translatedValues,
        ?string $fieldType = null
    ): array {
        if (!in_array($fieldType, ['checkbox', 'radio', 'dropdown'], true)) {
            return $selectedRawValues;
        }

        $selected = [];
        foreach ($selectedRawValues as $value) {
            $index = array_search($value, $allPossibleValues, true);
            if ($index !== false && array_key_exists($index, $translatedValues)) {
                $selected[] = $translatedValues[$index];
            } else {
                $selected[] = $value;
            }
        }

        return $selected;
    }

    public function seoSettings(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable',
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            $settings = new SeoSetting;
            if (! empty($request->page)) {
                $settings = $settings->where('page', $request->page);
            }

            $settings = $settings->get();
            ResponseService::successResponse(__('SEO settings fetched successfully.'), $settings);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> seoSettings');
            ResponseService::errorResponse();
        }
    }

    public function updateSellerSettings(Request $request)
{
    $validator = Validator::make($request->all(), [
        'show_phone' => 'nullable|boolean',
        'show_email' => 'nullable|boolean',
        'show_whatsapp' => 'nullable|boolean',
        'show_viber' => 'nullable|boolean',
        'whatsapp_number' => 'nullable|string|max:20',
        'viber_number' => 'nullable|string|max:20',
        'preferred_contact_method' => 'nullable|in:message,phone,whatsapp,viber,email',
        'business_hours' => 'nullable',
        'response_time' => 'nullable|in:auto,instant,few_hours,same_day,few_days',
        'accepts_offers' => 'nullable|boolean',
        'auto_reply_enabled' => 'nullable|boolean',
        'auto_reply_message' => 'nullable|string|max:300',
        'vacation_mode' => 'nullable|boolean',
        'vacation_message' => 'nullable|string|max:200',
        'business_description' => 'nullable|string|max:500',
        'return_policy' => 'nullable|string|max:300',
        'shipping_info' => 'nullable|string|max:300',
        'social_facebook' => 'nullable|string|max:255',
        'social_instagram' => 'nullable|string|max:255',
        'social_tiktok' => 'nullable|string|max:255',
        'social_youtube' => 'nullable|string|max:255',
        'social_website' => 'nullable|string|max:255',
        'card_preferences' => 'nullable|array',
    ]);
 
    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }
 
    try {
        $userId = Auth::id();
 
        $settings = \App\Models\SellerSetting::updateOrCreate(
            ['user_id' => $userId],
            [
                'show_phone' => $request->show_phone ?? true,
                'show_email' => $request->show_email ?? true,
                'show_whatsapp' => $request->show_whatsapp ?? false,
                'show_viber' => $request->show_viber ?? false,
                'whatsapp_number' => $request->whatsapp_number,
                'viber_number' => $request->viber_number,
                'preferred_contact_method' => $request->preferred_contact_method ?? 'message',
                'business_hours' => $request->business_hours,
                'response_time' => $request->response_time ?? 'auto',
                'accepts_offers' => $request->accepts_offers ?? true,
                'auto_reply_enabled' => $request->auto_reply_enabled ?? false,
                'auto_reply_message' => $request->auto_reply_message,
                'vacation_mode' => $request->vacation_mode ?? false,
                'vacation_message' => $request->vacation_message,
                'business_description' => $request->business_description,
                'return_policy' => $request->return_policy,
                'shipping_info' => $request->shipping_info,
                'social_facebook' => $request->social_facebook,
                'social_instagram' => $request->social_instagram,
                'social_tiktok' => $request->social_tiktok,
                'social_youtube' => $request->social_youtube,
                'social_website' => $request->social_website,
                'card_preferences' => $request->card_preferences,
            ]
        );
 
        ResponseService::successResponse(__('Settings updated successfully'), $settings);
 
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> updateSellerSettings');
        ResponseService::errorResponse();
    }
    }

    public function getCategories(Request $request)
{
    $validator = Validator::make($request->all(), [
        'language_code' => 'nullable',
    ]);

    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }

    try {
        $categories = Category::all();

        $languageCode = $request->get('language_code', 'en');
        $translator   = new GoogleTranslate($languageCode);

        $categoriesJson        = $categories->toJson();
        $translatedJson        = $translator->translate($categoriesJson);
        $translatedCategories  = json_decode($translatedJson, true);

        return ResponseService::successResponse(null, $translatedCategories);

        // NOTE: This line was unreachable in your original code because of the return above.
        // ResponseService::successResponse(null, $sql);
    } catch (Throwable $th) {
        ResponseService::logErrorResponse($th, 'API Controller -> getCategories');
        ResponseService::errorResponse();
    }
}


    public function bankTransferUpdate(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_transection_id' => 'required|integer',
                'payment_receipt' => 'required|file|mimes:jpg,jpeg,png|max:7048',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
            $transaction = PaymentTransaction::where('user_id', Auth::user()->id)->findOrFail($request->payment_transection_id);

            if (! $transaction) {
                return ResponseService::errorResponse(__('Transaction not found.'));
            }
            $receiptPath = ! empty($request->file('payment_receipt'))
            ? FileService::upload($request->file('payment_receipt'), 'bank-transfer')
            : '';
            $transaction->update([
                'payment_receipt' => $receiptPath,
                'payment_status' => 'under review',
            ]);

            return ResponseService::successResponse(__('Payment transaction updated successfully.'), $transaction);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> bankTransferUpdate');

            return ResponseService::errorResponse();
        }
    }

    public function getOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'number' => 'required|string',
                'intent' => 'nullable|in:login,register,profile_verification',
                'mobile' => 'nullable|string|max:32',
                'country_code' => 'nullable|string|max:8',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $intent = (string) $request->input('intent', 'login');
            $requestNumber = (string) $request->input('number', '');
            $trimmedNumber = ltrim($requestNumber, '+');
            $normalizedPhone = $this->normalizePhoneInput(
                $request->input('country_code'),
                $request->input('mobile', $trimmedNumber)
            );

            if ($normalizedPhone['mobile'] === '') {
                return ResponseService::validationError(__('Unesite ispravan broj telefona.'));
            }

            $toNumber = '+'.ltrim($normalizedPhone['full'] ?: $trimmedNumber, '+');
            AuthEventService::log('otp_send_attempt', [], 'info', $toNumber);

            $existingUser = $this->findPhoneConflict(
                $normalizedPhone['country'],
                $normalizedPhone['mobile'],
                null,
                false,
                true
            );

            if ($intent === 'login' && empty($existingUser)) {
                $this->phoneNotRegisteredResponse();
            }

            if ($intent === 'register' && ! empty($existingUser)) {
                return ResponseService::conflictResponse(
                    __('Broj telefona je već registrovan. Prijavite se ili koristite drugi broj.'),
                    ['reason' => 'phone_already_registered'],
                    config('constants.RESPONSE_CODE.CONFLICT')
                );
            }

            if ($intent === 'profile_verification') {
                if (! Auth::check()) {
                    return ResponseService::unauthorizedResponse(__('Unauthorized'));
                }

                $authUser = Auth::user();
                $conflict = $this->findPhoneConflict(
                    $normalizedPhone['country'],
                    $normalizedPhone['mobile'],
                    $authUser->id,
                    true,
                    false
                );

                if (! empty($conflict)) {
                    return ResponseService::conflictResponse(
                        __('Ovaj broj je već verificiran na drugom računu.'),
                        ['reason' => 'phone_already_verified_elsewhere'],
                        config('constants.RESPONSE_CODE.CONFLICT')
                    );
                }
            }

            // Fetch Twilio credentials from settings
            $twilioSettings = Setting::whereIn('name', [
                'twilio_account_sid', 'twilio_auth_token', 'twilio_my_phone_number',
            ])->pluck('value', 'name');

            if (! $twilioSettings->all()) {
                return ResponseService::errorResponse(__('Twilio settings are missing. Please contact admin.'));
            }

            $sid = $twilioSettings['twilio_account_sid'];
            $token = $twilioSettings['twilio_auth_token'];
            $fromNumber = $twilioSettings['twilio_my_phone_number'];

            $client = new TwilioRestClient($sid, $token);

            // Validate phone number using Twilio Lookup API
            try {
                $client->lookups->v1->phoneNumbers($toNumber)->fetch();
            } catch (Throwable $e) {
                return ResponseService::errorResponse(__('Invalid phone number.'));
            }

            $existingOtp = NumberOtp::where('number', $toNumber)->where('expire_at', '>', now())->first();
            $otp = $existingOtp ? $existingOtp->otp : rand(100000, 999999);
            $expireAt = now()->addMinutes(10);

            NumberOtp::updateOrCreate(
                ['number' => $toNumber],
                ['otp' => $otp, 'expire_at' => $expireAt]
            );

            // Send OTP via Twilio
            $client->messages->create($toNumber, [
                'from' => $fromNumber,
                'body' => "Your OTP is: $otp. It expires in 10 minutes.",
            ]);
            AuthEventService::log('otp_send_success', [], 'success', $toNumber);

            return ResponseService::successResponse(__('OTP sent successfully.'));
        } catch (Throwable $th) {
            AuthEventService::log('otp_send_failed', [
                'error' => $th->getMessage(),
            ], 'error', (string) $request->input('number'));
            ResponseService::logErrorResponse($th, 'OTP Controller -> getOtp');

            return ResponseService::errorResponse();
        }
    }

    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'number' => 'required|string',
                'otp' => 'required|numeric|digits:6',
                'intent' => 'nullable|in:login,register,profile_verification',
                'mobile' => 'nullable|string|max:32',
                'country_code' => 'nullable|string|max:8',
                'region_code' => 'nullable|string|max:8',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $requestNumber = $request->number;
            $trimmedNumber = ltrim($requestNumber, '+');
            $toNumber = '+'.$trimmedNumber;
            AuthEventService::log('otp_verify_attempt', [
                'intent' => $request->input('intent', 'login'),
            ], 'info', $toNumber);

            $otpRecord = NumberOtp::where('number', $toNumber)->first();

            if (! $otpRecord) {
                AuthEventService::log('otp_verify_failed', ['reason' => 'otp_not_found'], 'warning', $toNumber);
                return ResponseService::errorResponse(__('OTP not found.'));
            }
            if (now()->isAfter($otpRecord->expire_at)) {
                AuthEventService::log('otp_verify_failed', ['reason' => 'otp_expired'], 'warning', $toNumber);
                return ResponseService::validationError(__('OTP has expired.'));
            }

            if ($otpRecord->attempts >= 3) {
                $otpRecord->delete();
                AuthEventService::log('otp_verify_failed', ['reason' => 'max_attempts_reached'], 'warning', $toNumber);

                return ResponseService::validationError(__('OTP expired after 3 failed attempts.'));
            }

            if ($otpRecord->otp != $request->otp) {
                $otpRecord->increment('attempts');
                AuthEventService::log('otp_verify_failed', ['reason' => 'invalid_otp'], 'warning', $toNumber);

                return ResponseService::validationError(__('Invalid OTP.'));
            }
            $otpRecord->delete();

            $intent = $request->input('intent', 'login');

            if ($intent === 'profile_verification') {
                if (! Auth::check()) {
                    return ResponseService::errorResponse(__('Unauthorized'));
                }

                $authUser = Auth::user();
                $normalized = $this->normalizePhoneInput(
                    $request->input('country_code'),
                    $request->input('mobile', $trimmedNumber)
                );

                if ($normalized['mobile'] === '') {
                    return ResponseService::validationError(__('Unesite ispravan broj telefona.'));
                }

                $conflict = $this->findPhoneConflict(
                    $normalized['country'],
                    $normalized['mobile'],
                    $authUser->id,
                    true,
                    false
                );

                if (! empty($conflict)) {
                    return ResponseService::conflictResponse(
                        __('Ovaj broj je već verificiran na drugom računu.'),
                        ['reason' => 'phone_already_verified_elsewhere'],
                        config('constants.RESPONSE_CODE.CONFLICT')
                    );
                }

                $authUser->update([
                    'mobile' => $normalized['mobile'],
                    'country_code' => $normalized['country'] ?: null,
                    'region_code' => strtoupper((string) $request->input('region_code', $authUser->region_code ?? 'BA')) ?: 'BA',
                    'phone_verified_at' => now(),
                ]);

                $authUser->refresh();
                AuthEventService::log('otp_verify_success', [
                    'intent' => 'profile_verification',
                    'user_id' => $authUser->id,
                ], 'success', $toNumber, $authUser->id);
                return ResponseService::successResponse(__('Broj telefona je uspješno verificiran.'), $authUser);
            }

            $loginCountryCode = $this->normalizePhoneDigits($request->input('country_code'));
            if ($loginCountryCode === '' && Str::startsWith($trimmedNumber, '387')) {
                $loginCountryCode = '387';
            }
            $loginMobile = $trimmedNumber;
            if ($loginCountryCode !== '' && Str::startsWith($trimmedNumber, $loginCountryCode)) {
                $loginMobile = substr($trimmedNumber, strlen($loginCountryCode)) ?: $trimmedNumber;
            }

            $user = $this->findPhoneConflict(
                $loginCountryCode,
                $loginMobile,
                null,
                false,
                false
            );

            $registerFallbackToLogin = $intent === 'register' && ! empty($user);

            if (! $user) {
                if ($intent === 'login') {
                    $this->phoneNotRegisteredResponse();
                }

                $defaultCountryCode = $loginCountryCode ?: null;
                $normalizedForStore = $this->normalizePhoneInput($defaultCountryCode, $trimmedNumber);
                $mobileToStore = $normalizedForStore['mobile'] !== ''
                    ? $normalizedForStore['mobile']
                    : $trimmedNumber;

                $user = User::create([
                    'mobile' => $mobileToStore,
                    'country_code' => $normalizedForStore['country'] ?: $defaultCountryCode,
                    'type' => 'phone',
                    'phone_verified_at' => now(),
                ]);
                $user->assignRole('User');
            }

            if (! $user->hasRole('User')) {
                return ResponseService::errorResponse(__('Invalid Login Credentials'));
            }

            if (empty($user->phone_verified_at)) {
                $user->phone_verified_at = now();
            }
            if (empty($user->country_code) && ! empty($loginCountryCode)) {
                $user->country_code = $loginCountryCode;
            }
            $user->save();

            Auth::login($user);
            $auth = User::find(Auth::id());

            $token = $auth->createToken($auth->name ?? '')->plainTextToken;
            $this->persistTokenSessionMetadata($token, $request, $request->platform_type);
            AuthEventService::log('otp_verify_success', [
                'intent' => $intent,
                'user_id' => $auth->id,
                'register_fallback_login' => $registerFallbackToLogin,
            ], 'success', $toNumber, $auth->id);

            $successMessage = $registerFallbackToLogin
                ? __('Broj je već registrovan. Prijavili smo vas na postojeći račun.')
                : __('User logged-in successfully');

            return ResponseService::successResponse($successMessage, $auth, [
                'token' => $token,
                'meta' => [
                    'register_fallback_login' => $registerFallbackToLogin,
                ],
            ]);
        } catch (Throwable $th) {
            AuthEventService::log('otp_verify_failed', [
                'reason' => 'exception',
                'error' => $th->getMessage(),
            ], 'error', (string) $request->input('number'));
            ResponseService::logErrorResponse($th, 'OTP Controller -> verifyOtp');

            return ResponseService::errorResponse();
        }
    }

    public function applyJob(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required',
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'mobile' => 'required|string|max:20',
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:7168',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $userId = Auth::id();
            $post = Item::approved()->notOwner()->findOrFail($request->item_id);
            $alreadyApplied = JobApplication::where('item_id', $request->item_id)
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyApplied) {
                return ResponseService::validationError(__('You have already applied for this job.'));
            }
            $resumePath = null;
            if ($request->hasFile('resume')) {
                $resumePath = FileService::upload($request->resume, 'job_resume');
            }

            $application = JobApplication::create([
                'item_id' => $post->id,
                'user_id' => Auth::user()->id,
                'recruiter_id' => $post->user_id,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'resume' => $resumePath,
            ]);

            $user_token = UserFcmToken::where('user_id', $post->user_id)->pluck('fcm_token')->toArray();
            if (! empty($user_token)) {
                NotificationService::sendFcmNotification($user_token, 'New Job Application', $request->full_name.' applied for your job post: '.$post->name, 'job-application', ['item_id' => $post->id]
                );
            }

            return ResponseService::successResponse(__('Application submitted successfully.'), $application);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> applyJob');

            return ResponseService::errorResponse();
        }
    }

    public function recruiterApplications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'nullable|integer',
            'page' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user();

            $applications = JobApplication::where('recruiter_id', $user->id)
                ->with('user:id,name,email', 'item:id,name');
            if (! empty($request->item_id)) {
                $applications->where('item_id', $request->item_id);
            }

            $applications = $applications->latest()->paginate();

            return ResponseService::successResponse(__('Recruiter applications fetched'), $applications);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> recruiterApplications');

            return ResponseService::errorResponse();
        }
    }

    public function myJobApplications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'nullable|integer',
            'page' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user = Auth::user();

            $applications = JobApplication::where('user_id', $user->id);

            if (! empty($request->item_id)) {
                $applications->where('item_id', $request->item_id);
            }

            $applications = $applications->with([
                'item:id,name,user_id',
                'recruiter:id,name,email',
            ])
                ->latest()
                ->paginate();

            return ResponseService::successResponse(__('Your job applications fetched'), $applications);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> myJobApplications');

            return ResponseService::errorResponse();
        }
    }

    public function updateJobStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'job_id' => 'required|exists:job_applications,id',
            'status' => 'required|in:accepted,rejected',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $application = JobApplication::with('item')->findOrFail($request->job_id);

            if ($application->recruiter_id !== $user->id) {
                return ResponseService::errorResponse(__('Unauthorized to update this job status.'), 403);
            }

            $application->update(['status' => $request->status]);

            // Optional: Notify the applicant
            $user_token = UserFcmToken::where('user_id', $application->user_id)->pluck('fcm_token')->toArray();
            if (! empty($user_token)) {
                NotificationService::sendFcmNotification(
                    $user_token,
                    'Application '.ucfirst($request->status),
                    'Your application for job post has been '.$request->status,
                    'application-status',
                    ['job_id' => $application->id]
                );
            }

            return ResponseService::successResponse(__('Application status updated.'), $application);

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> updateJobStatus');

            return ResponseService::errorResponse();
        }
    }

    public function getLocationFromCoordinates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'long' => 'nullable|numeric|between:-180,180',
            'longitude' => 'nullable|numeric|between:-180,180',
            'lang' => 'nullable|string|max:10',
            'search' => 'nullable|string|max:250',
            'place_id' => 'nullable|string|max:255',
            'session_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $requestId = trim((string) ($request->header('X-Request-Id') ?? ''));
            if ($requestId === '') {
                $requestId = (string) Str::uuid();
            }
            $startedAt = microtime(true);

            $lat = $this->parseCoordinateValue($request->input('lat'));
            $lng = $this->parseCoordinateValue($request->input('lng'));
            if ($lng === null) {
                $lng = $this->parseCoordinateValue($request->input('long'));
            }
            if ($lng === null) {
                $lng = $this->parseCoordinateValue($request->input('longitude'));
            }

            $lang = trim((string) ($request->input('lang') ?? 'en'));
            if ($lang === '') {
                $lang = 'en';
            }
            $search = trim((string) ($request->input('search') ?? ''));
            $placeId = trim((string) ($request->input('place_id') ?? ''));
            $sessionId = trim((string) ($request->input('session_id') ?? ''));
            $mapProvider = Setting::where('name', 'map_provider')->value('value') ?? 'free_api';
            $scope = $search !== '' ? 'search' : (($lat !== null && $lng !== null) ? 'coordinates' : ($placeId !== '' ? 'place_id' : 'generic'));
            $rateLimitConfig = $this->getLocationLookupRateLimitConfig($scope);
            $rateLimitKey = $this->buildLocationRateLimitKey($request, $scope);

            if (RateLimiter::tooManyAttempts($rateLimitKey, $rateLimitConfig['max_attempts'])) {
                $retryAfter = RateLimiter::availableIn($rateLimitKey);
                Log::warning('location.lookup.rate_limited', [
                    'request_id' => $requestId,
                    'scope' => $scope,
                    'ip' => $request->ip(),
                    'user_id' => optional($request->user())->id,
                    'retry_after_seconds' => $retryAfter,
                    'max_attempts' => $rateLimitConfig['max_attempts'],
                ]);

                return ResponseService::errorResponse(
                    __('Too many location requests. Please try again shortly.'),
                    [
                        'retry_after_seconds' => $retryAfter,
                        'request_id' => $requestId,
                    ],
                    'LOCATION_LOOKUP_RATE_LIMITED',
                    null,
                    429
                );
            }

            RateLimiter::hit($rateLimitKey, $rateLimitConfig['decay_seconds']);

            // Determine current language ID
            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLangId = (int) (Language::where('code', $contentLangCode)->value('id') ?? 1);
            $cacheTtlSeconds = $this->resolveLocationLookupCacheTtlSeconds($scope);
            $cacheKey = $this->buildLocationLookupCacheKey([
                'scope' => $scope,
                'map_provider' => $mapProvider,
                'lang' => $lang,
                'language_id' => $currentLangId,
                'search' => Str::lower($search),
                'place_id' => Str::lower($placeId),
                'lat' => $lat !== null ? round((float) $lat, 5) : null,
                'lng' => $lng !== null ? round((float) $lng, 5) : null,
            ]);
            $isCacheable = !($mapProvider === 'google_places' && $sessionId !== '');
            $cacheHit = false;

            if ($isCacheable) {
                $cachedResult = Cache::get($cacheKey);
                if (is_array($cachedResult) && array_key_exists('data', $cachedResult)) {
                    $cacheHit = true;
                    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                    Log::info('location.lookup.cache_hit', [
                        'request_id' => $requestId,
                        'scope' => $scope,
                        'source' => $cachedResult['source'] ?? 'cache',
                        'ip' => $request->ip(),
                        'user_id' => optional($request->user())->id,
                        'duration_ms' => $durationMs,
                    ]);

                    return ResponseService::successResponse(
                        $cachedResult['message'] ?? __('Location fetched from cache'),
                        $cachedResult['data'],
                        [
                            'trace_id' => $requestId,
                            'meta' => [
                                'scope' => $scope,
                                'source' => $cachedResult['source'] ?? 'cache',
                                'cache_hit' => true,
                                'duration_ms' => $durationMs,
                            ],
                        ]
                    );
                }
            }

            $successResponse = function (string $message, $payload, string $source = 'unknown', bool $allowCache = true) use (
                $isCacheable,
                $cacheKey,
                $cacheTtlSeconds,
                $request,
                $requestId,
                $scope,
                $startedAt,
                $cacheHit
            ) {
                $normalizedPayload = $payload;
                if ($payload instanceof \Illuminate\Support\Collection) {
                    $normalizedPayload = $payload->values()->toArray();
                } elseif ($payload instanceof \Illuminate\Contracts\Support\Arrayable) {
                    $normalizedPayload = $payload->toArray();
                }

                if ($allowCache && $isCacheable) {
                    Cache::put($cacheKey, [
                        'message' => $message,
                        'source' => $source,
                        'data' => $normalizedPayload,
                    ], now()->addSeconds($cacheTtlSeconds));
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                Log::info('location.lookup.success', [
                    'request_id' => $requestId,
                    'scope' => $scope,
                    'source' => $source,
                    'cache_hit' => $cacheHit,
                    'ip' => $request->ip(),
                    'user_id' => optional($request->user())->id,
                    'duration_ms' => $durationMs,
                ]);

                return ResponseService::successResponse(
                    $message,
                    $normalizedPayload,
                    [
                        'trace_id' => $requestId,
                        'meta' => [
                            'scope' => $scope,
                            'source' => $source,
                            'cache_hit' => $cacheHit,
                            'duration_ms' => $durationMs,
                        ],
                    ]
                );
            };

            $errorResponse = function (string $message, int $status = 500, string $code = 'LOCATION_LOOKUP_FAILED', ?array $data = null) use ($request, $requestId, $scope, $startedAt) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                Log::warning('location.lookup.failed', [
                    'request_id' => $requestId,
                    'scope' => $scope,
                    'status' => $status,
                    'code' => $code,
                    'message' => $message,
                    'ip' => $request->ip(),
                    'user_id' => optional($request->user())->id,
                    'duration_ms' => $durationMs,
                ]);

                $payload = array_merge($data ?? [], [
                    'request_id' => $requestId,
                    'scope' => $scope,
                    'duration_ms' => $durationMs,
                ]);

                return ResponseService::errorResponse($message, $payload, $code, null, $status);
            };

            $resolveTranslatedName = static function ($model, int $languageId, string $fallbackField = 'name'): ?string {
                if (empty($model)) {
                    return null;
                }

                $fallback = $model->{$fallbackField} ?? null;
                if (!isset($model->translations) || !is_iterable($model->translations)) {
                    return $fallback;
                }

                $translation = collect($model->translations)->firstWhere('language_id', $languageId);
                return !empty($translation?->name) ? $translation->name : $fallback;
            };

            $resolveCountryTranslatedName = static function ($country, int $languageId): ?string {
                if (empty($country)) {
                    return null;
                }

                $fallback = $country->name ?? null;
                $translations = null;

                if (isset($country->nameTranslations) && is_iterable($country->nameTranslations)) {
                    $translations = $country->nameTranslations;
                } elseif (isset($country->translations) && is_iterable($country->translations)) {
                    $translations = $country->translations;
                }

                if (empty($translations)) {
                    return $fallback;
                }

                $translation = collect($translations)->firstWhere('language_id', $languageId);
                return !empty($translation?->name) ? $translation->name : $fallback;
            };

            /**
             * 🔍 Handle search query
             */
            if ($search !== '') {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return $errorResponse(__('Google Maps API key not set'), 500, 'GOOGLE_MAPS_API_KEY_MISSING');
                    }

                    $googleParams = [
                        'key' => $apiKey,
                        'input' => $search,
                        'language' => $lang,
                    ];
                    if ($sessionId !== '') {
                        $googleParams['sessiontoken'] = $sessionId;
                    }

                    $response = Http::timeout(8)->retry(2, 250)
                        ->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', $googleParams);

                    return $response->successful()
                        ? $successResponse(__('Location fetched from Google API'), $response->json(), 'google_places_search', false)
                        : $errorResponse(__('Failed to fetch from Google Maps API'), 502, 'GOOGLE_MAPS_UPSTREAM_FAILED');

                } else {
                    // Search Areas with translations
                    $areas = Area::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.state.country.nameTranslations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('name', 'like', "%{$search}%")
                        ->limit(10)
                        ->get();

                    if ($areas->isNotEmpty()) {
                        return $successResponse(__('Matching areas found'), $areas->map(function ($area) use ($resolveTranslatedName, $resolveCountryTranslatedName, $currentLangId) {
                            $city = $area->city;
                            $state = $city?->state;
                            $country = $state?->country;

                            return [
                                'area_id' => $area->id,
                                'area' => $area->name,
                                'area_translation' => optional($area->translations->first())->name ?? $area->name,
                                'city_id' => $city?->id,
                                'city' => $city?->name,
                                'city_translation' => $resolveTranslatedName($city, $currentLangId) ?? $city?->name,
                                'state' => $state?->name,
                                'state_translation' => $resolveTranslatedName($state, $currentLangId) ?? $state?->name,
                                'country' => $country?->name,
                                'country_translation' => $resolveCountryTranslatedName($country, $currentLangId) ?? $country?->name,
                                'latitude' => $area->latitude,
                                'longitude' => $area->longitude,
                            ];
                        }), 'local_area_search');
                    }

                    // Search Cities with translations
                    $cities = City::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.country.nameTranslations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('state', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('state.country', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->limit(10)
                        ->get();

                    if ($cities->isNotEmpty()) {
                        return $successResponse(__('Matching cities found'), $cities->map(function ($city) use ($resolveTranslatedName, $resolveCountryTranslatedName, $currentLangId) {
                            $state = $city->state;
                            $country = $state?->country;

                            return [
                                'city_id' => $city->id,
                                'city' => $city->name,
                                'city_translation' => $resolveTranslatedName($city, $currentLangId) ?? $city->name,
                                'state' => $state?->name,
                                'state_translation' => $resolveTranslatedName($state, $currentLangId) ?? $state?->name,
                                'country' => $country?->name,
                                'country_translation' => $resolveCountryTranslatedName($country, $currentLangId) ?? $country?->name,
                                'latitude' => $city->latitude,
                                'longitude' => $city->longitude,
                            ];
                        }), 'local_city_search');
                    }

                    $municipalities = BihMunicipality::query()
                        ->where('name', 'like', "%{$search}%")
                        ->with(['region:id,code,name', 'region.entity:id,code,name,short_name'])
                        ->limit(10)
                        ->get();

                    if ($municipalities->isNotEmpty()) {
                        return $successResponse(__('Matching municipalities found'), $municipalities->map(function ($municipality) {
                            return [
                                'city_id' => null,
                                'city' => $municipality->name,
                                'city_translation' => $municipality->name,
                                'state' => optional($municipality->region)->name,
                                'state_translation' => optional($municipality->region)->name,
                                'country' => 'Bosna i Hercegovina',
                                'country_translation' => 'Bosna i Hercegovina',
                                'area_id' => null,
                                'area' => null,
                                'area_translation' => null,
                                'latitude' => $municipality->latitude,
                                'longitude' => $municipality->longitude,
                            ];
                        }), 'local_municipality_search');
                    }

                    $nominatimCoordinates = $this->resolveCoordinatesViaNominatim([
                        $search,
                        "{$search}, Bosna i Hercegovina",
                        "{$search}, BiH",
                    ]);
                    if ($nominatimCoordinates) {
                        return $successResponse(__('Location fetched from geocoder'), [
                            'city_id' => null,
                            'city' => $search,
                            'city_translation' => $search,
                            'state' => null,
                            'state_translation' => null,
                            'country' => 'Bosna i Hercegovina',
                            'country_translation' => 'Bosna i Hercegovina',
                            'area_id' => null,
                            'area' => null,
                            'area_translation' => null,
                            'latitude' => $nominatimCoordinates['lat'],
                            'longitude' => $nominatimCoordinates['lng'],
                        ], 'nominatim_search');
                    }

                    return $errorResponse(__('No matching location found'), 404, 'LOCATION_NOT_FOUND');
                }
            }

            /**
             * 📍 Get location by coordinates
             */
            if ($lat !== null && $lng !== null) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return $errorResponse(__('Google Maps API key not set'), 500, 'GOOGLE_MAPS_API_KEY_MISSING');
                    }

                    $googleParams = [
                        'latlng' => "{$lat},{$lng}",
                        'key' => $apiKey,
                        'language' => $lang,
                    ];
                    if ($sessionId !== '') {
                        $googleParams['sessiontoken'] = $sessionId;
                    }

                    $response = Http::timeout(8)->retry(2, 250)
                        ->get('https://maps.googleapis.com/maps/api/geocode/json', $googleParams);

                    return $response->successful()
                        ? $successResponse(__('Location fetched from Google API'), $response->json(), 'google_reverse_geocode', false)
                        : $errorResponse(__('Failed to fetch from Google Maps API'), 502, 'GOOGLE_MAPS_UPSTREAM_FAILED');

                } else {
                    $closestCity = City::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.country.nameTranslations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->selectRaw('
                        id, name, latitude, longitude, state_id,
                        (6371 * acos(cos(radians(?))
                            * cos(radians(latitude))
                            * cos(radians(longitude) - radians(?))
                            + sin(radians(?))
                            * sin(radians(latitude)))) AS distance
                    ', [$lat, $lng, $lat])
                        ->orderBy('distance', 'asc')
                        ->first();

                    if (! $closestCity) {
                        $closestMunicipality = BihMunicipality::query()
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude')
                            ->selectRaw('
                                id, name, latitude, longitude, region_id,
                                (6371 * acos(cos(radians(?))
                                    * cos(radians(latitude))
                                    * cos(radians(longitude) - radians(?))
                                    + sin(radians(?))
                                    * sin(radians(latitude)))) AS distance
                            ', [$lat, $lng, $lat])
                            ->orderBy('distance', 'asc')
                            ->first();

                        if ($closestMunicipality) {
                            return $successResponse(__('Location fetched from local database'), [
                                'city_id' => null,
                                'city' => $closestMunicipality->name,
                                'city_translation' => $closestMunicipality->name,
                                'state' => optional($closestMunicipality->region)->name,
                                'state_translation' => optional($closestMunicipality->region)->name,
                                'country' => 'Bosna i Hercegovina',
                                'country_translation' => 'Bosna i Hercegovina',
                                'area_id' => null,
                                'area' => null,
                                'area_translation' => null,
                                'latitude' => $closestMunicipality->latitude,
                                'longitude' => $closestMunicipality->longitude,
                            ], 'local_municipality_reverse');
                        }

                        $reverseGeocoded = $this->resolveReverseLocationViaNominatim($lat, $lng, $lang);
                        if ($reverseGeocoded) {
                            return $successResponse(__('Location fetched from geocoder'), $reverseGeocoded, 'nominatim_reverse');
                        }

                        return $errorResponse(__('No nearby city found'), 404, 'LOCATION_NOT_FOUND');
                    }

                    $closestArea = Area::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('city_id', $closestCity->id)
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->selectRaw('
                        id, name, latitude, longitude, city_id,
                        (6371 * acos(cos(radians(?))
                            * cos(radians(latitude))
                            * cos(radians(longitude) - radians(?))
                            + sin(radians(?))
                            * sin(radians(latitude)))) AS distance
                    ', [$lat, $lng, $lat])
                        ->orderBy('distance', 'asc')
                        ->first();

                    $closestState = $closestCity->state;
                    $closestCountry = $closestState?->country;

                    return $successResponse(__('Location fetched from local database'), [
                        'city_id' => $closestCity->id,
                        'city' => $closestCity->name,
                        'city_translation' => $resolveTranslatedName($closestCity, $currentLangId) ?? $closestCity->name,
                        'state' => $closestState?->name,
                        'state_translation' => $resolveTranslatedName($closestState, $currentLangId) ?? $closestState?->name,
                        'country' => $closestCountry?->name,
                        'country_translation' => $resolveCountryTranslatedName($closestCountry, $currentLangId) ?? $closestCountry?->name,
                        'area_id' => optional($closestArea)->id,
                        'area' => optional($closestArea)->name,
                        'area_translation' => optional($closestArea?->translations?->first())->name ?? $closestArea?->name,
                        'latitude' => $closestCity->latitude,
                        'longitude' => $closestCity->longitude,
                    ], 'local_city_reverse');
                }
            }

            /**
             * 🏷️ Handle place_id
             */
            if ($placeId) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return $errorResponse(__('Google Maps API key not set'), 500, 'GOOGLE_MAPS_API_KEY_MISSING');
                    }
                    $googleParams = [
                        'place_id' => $placeId,
                        'key' => $apiKey,
                        'language' => $lang,
                    ];
                    if ($sessionId !== '') {
                        $googleParams['sessiontoken'] = $sessionId;
                    }

                    $response = Http::timeout(8)->retry(2, 250)
                        ->get('https://maps.googleapis.com/maps/api/geocode/json', $googleParams);

                    return $response->successful()
                        ? $successResponse(__('Location fetched from place_id'), $response->json(), 'google_place_id', false)
                        : $errorResponse(__('Failed to fetch from Google Maps API using place_id'), 502, 'GOOGLE_MAPS_UPSTREAM_FAILED');
                } else {
                    return $errorResponse(__('place_id is only supported with Google Maps provider'), 422, 'PLACE_ID_UNSUPPORTED');
                }
            }

            return $errorResponse(__('Please provide search text, coordinates or place_id.'), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $th) {
            Log::error('location.lookup.exception', [
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ]);
            ResponseService::logErrorResponse($th, 'API Controller -> getLocationFromCoordinates');

            return ResponseService::errorResponse(__('Failed to fetch location'));
        }
    }

    public function subscribeFCMTopic(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'topic' => 'required|string',
        ]);

        $serverKey = env('FIREBASE_SERVER_KEY'); // legacy server key
        $url = "https://iid.googleapis.com/iid/v1/{$request->fcm_token}/rel/topics/{$request->topic}";

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'access_token_auth' => true,
            'Content-Type' => 'application/json',
        ])->post($url);

        if ($response->successful()) {
            return response()->json(['error' => false, 'message' => 'Subscribed successfully']);
        }

        return response()->json(['error' => true, 'details' => $response->body()], $response->status());
    }

    public function getItemSlugs(Request $request)
    {
        try {
            $items = Item::without('translations')
                ->select('id', 'slug', 'updated_at')
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->getNonExpiredItems()
                ->get()
                ->each->setAppends([]);

            if ($items->isEmpty()) {
                return ResponseService::errorResponse(__('No active items found.'));
            }

            return ResponseService::successResponse(__('Active item slugs fetched successfully.'), $items);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getItemSlugs');

            return ResponseService::errorResponse();
        }
    }

    public function getCategoriesSlug(Request $request)
    {
        try {
            $categories = Category::without('translations')
                ->select('id', 'slug', 'updated_at')
                ->where('status', 1)
                ->get()
                ->each->setAppends([]);

            if ($categories->isEmpty()) {
                return ResponseService::errorResponse(__('No active Categories found.'));
            }

            return ResponseService::successResponse(__('Active Categories slugs fetched successfully.'), $categories);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategoriesSlug');
            ResponseService::errorResponse();
        }
    }

    public function getBlogsSlug(Request $request)
    {
        try {
            $blogs = Blog::without('translations')
                ->select('id', 'slug', 'updated_at')
                ->get()
                ->each->setAppends([]);

            if ($blogs->isEmpty()) {
                return ResponseService::errorResponse(__('No active Blogs found.'));
            }

            return ResponseService::successResponse(__('Active Blogs slugs fetched successfully.'), $blogs);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategoriesSlug');
            ResponseService::errorResponse();
        }
    }

    public function getFeatureSectionSlug(Request $request)
    {
        try {
            $FeatureSection = FeatureSection::without('translations')
                ->select('id', 'slug', 'updated_at')
                ->get()
                ->each->setAppends([]);

            if ($FeatureSection->isEmpty()) {
                return ResponseService::errorResponse(__('No active Feature Sections found.'));
            }

            return ResponseService::successResponse(__('Active Feature Sections slugs fetched successfully.'), $FeatureSection);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategoriesSlug');
            ResponseService::errorResponse();
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = Auth::user();
            $validator = Validator::make($request->all(), [
                'fcm_token' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            if ($request->fcm_token) {
                UserFcmToken::where('user_id', $user->id)
                ->where('fcm_token', $request->fcm_token)
                ->delete();
            }

            $currentToken = $user?->currentAccessToken();
            if ($currentToken) {
                $currentToken->delete();
            }

            return ResponseService::successResponse(__('User logged out successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> Logout');

            return ResponseService::errorResponse();
        }
    }

    public function getActiveSessions(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ResponseService::errorResponse(__('User not authenticated'));
            }

            $currentToken = $user->currentAccessToken();
            $currentSessionId = $currentToken?->id;

            if ($currentToken) {
                $this->updateCurrentTokenSessionMetadata($request, $currentToken, $request->platform_type);
            }

            $columnSupport = $this->getSessionMetadataColumnSupport();
            $selectColumns = ['id', 'name', 'last_used_at', 'created_at'];

            if ($columnSupport['device_name']) $selectColumns[] = 'device_name';
            if ($columnSupport['platform']) $selectColumns[] = 'platform';
            if ($columnSupport['ip_address']) $selectColumns[] = 'ip_address';
            if ($columnSupport['user_agent']) $selectColumns[] = 'user_agent';

            $tokensQuery = $user->tokens()->select($selectColumns);
            if (!empty($currentSessionId)) {
                $tokensQuery->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$currentSessionId]);
            }
            $tokens = $tokensQuery
                ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
                ->get();

            $sessions = $tokens->map(function ($token) use ($currentSessionId) {
                $ua = $token->user_agent ?? '';
                $parsed = $this->parseSessionDeviceInfo($ua, $token->platform ?? null);

                $deviceName = trim((string) ($token->device_name ?? ''));
                if ($deviceName === '') {
                    $deviceName = $parsed['device_name'];
                }

                $platform = trim((string) ($token->platform ?? ''));
                if ($platform === '') {
                    $platform = $parsed['platform'];
                }

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'is_current' => !empty($currentSessionId) && (int) $token->id === (int) $currentSessionId,
                    'device_name' => $deviceName,
                    'platform' => $platform,
                    'device_type' => $parsed['device_type'],
                    'os' => $parsed['os'],
                    'browser' => $parsed['browser'],
                    'ip_address' => $token->ip_address ?? null,
                    'user_agent' => $ua ?: null,
                    'last_used_at' => $token->last_used_at,
                    'created_at' => $token->created_at,
                    'last_active_at' => $token->last_used_at ?? $token->created_at,
                ];
            })->values();

            return ResponseService::successResponse(__('Data Fetched Successfully'), [
                'current_session_id' => $currentSessionId,
                'sessions' => $sessions,
                'total_sessions' => $sessions->count(),
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getActiveSessions');
            return ResponseService::errorResponse();
        }
    }

    public function logoutAllDevices(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ResponseService::errorResponse(__('User not authenticated'));
            }

            $validator = Validator::make($request->all(), [
                'keep_current' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $keepCurrent = filter_var($request->input('keep_current', false), FILTER_VALIDATE_BOOLEAN);
            $currentTokenId = $user->currentAccessToken()?->id;

            $tokensQuery = $user->tokens();
            if ($keepCurrent && !empty($currentTokenId)) {
                $tokensQuery->where('id', '!=', $currentTokenId);
            }

            $revokedCount = (clone $tokensQuery)->count();
            $tokensQuery->delete();

            UserFcmToken::where('user_id', $user->id)->delete();

            return ResponseService::successResponse('Sesije su uspješno zatvorene.', [
                'revoked_count' => $revokedCount,
                'keep_current' => $keepCurrent,
                'current_session_id' => $keepCurrent ? $currentTokenId : null,
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> logoutAllDevices');
            return ResponseService::errorResponse();
        }
    }

    private function getSessionMetadataColumnSupport(): array
    {
        static $columnSupport = null;

        if ($columnSupport !== null) {
            return $columnSupport;
        }

        $table = 'personal_access_tokens';
        $columnSupport = [
            'device_name' => Schema::hasColumn($table, 'device_name'),
            'platform' => Schema::hasColumn($table, 'platform'),
            'ip_address' => Schema::hasColumn($table, 'ip_address'),
            'user_agent' => Schema::hasColumn($table, 'user_agent'),
        ];

        return $columnSupport;
    }

    private function parseSessionDeviceInfo(?string $userAgent, ?string $platformHint = null): array
    {
        $ua = Str::lower((string) $userAgent);

        $deviceType = 'desktop';
        if (Str::contains($ua, ['ipad', 'tablet'])) {
            $deviceType = 'tablet';
        } elseif (Str::contains($ua, ['mobile', 'iphone', 'android'])) {
            $deviceType = 'mobile';
        }

        $os = 'Nepoznat OS';
        if (Str::contains($ua, ['iphone', 'ipad', 'ios'])) {
            $os = 'iOS';
        } elseif (Str::contains($ua, ['android'])) {
            $os = 'Android';
        } elseif (Str::contains($ua, ['windows'])) {
            $os = 'Windows';
        } elseif (Str::contains($ua, ['mac os', 'macintosh', 'macos'])) {
            $os = 'macOS';
        } elseif (Str::contains($ua, ['linux'])) {
            $os = 'Linux';
        }

        $browser = 'Nepoznat preglednik';
        if (Str::contains($ua, ['edg/'])) {
            $browser = 'Edge';
        } elseif (Str::contains($ua, ['opr/', 'opera'])) {
            $browser = 'Opera';
        } elseif (Str::contains($ua, ['chrome/']) && !Str::contains($ua, ['edg/', 'opr/'])) {
            $browser = 'Chrome';
        } elseif (Str::contains($ua, ['safari/']) && !Str::contains($ua, ['chrome/'])) {
            $browser = 'Safari';
        } elseif (Str::contains($ua, ['firefox/'])) {
            $browser = 'Firefox';
        }

        $platform = Str::lower(trim((string) $platformHint));
        if ($platform === '') {
            if ($os === 'Android') {
                $platform = 'android';
            } elseif ($os === 'iOS') {
                $platform = 'ios';
            } else {
                $platform = 'web';
            }
        }

        $deviceTypeLabel = match ($deviceType) {
            'mobile' => 'Mobilni uređaj',
            'tablet' => 'Tablet',
            default => 'Računar',
        };

        return [
            'device_type' => $deviceType,
            'platform' => $platform,
            'os' => $os,
            'browser' => $browser,
            'device_name' => "{$browser} - {$os} ({$deviceTypeLabel})",
        ];
    }

    private function persistTokenSessionMetadata(?string $plainTextToken, Request $request, ?string $platformHint = null): void
    {
        if (empty($plainTextToken)) {
            return;
        }

        $parts = explode('|', (string) $plainTextToken, 2);
        $tokenId = isset($parts[0]) ? (int) $parts[0] : 0;

        if ($tokenId <= 0) {
            return;
        }

        $columnSupport = $this->getSessionMetadataColumnSupport();
        $sessionData = $this->parseSessionDeviceInfo($request->userAgent(), $platformHint);

        $updates = [];
        if ($columnSupport['device_name']) $updates['device_name'] = $sessionData['device_name'];
        if ($columnSupport['platform']) $updates['platform'] = $sessionData['platform'];
        if ($columnSupport['ip_address']) $updates['ip_address'] = $request->ip();
        if ($columnSupport['user_agent']) $updates['user_agent'] = Str::limit((string) $request->userAgent(), 65000, '');

        if (empty($updates)) {
            return;
        }

        PersonalAccessToken::query()
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->update($updates);
    }

    private function updateCurrentTokenSessionMetadata(Request $request, $currentToken, ?string $platformHint = null): void
    {
        if (!$currentToken) {
            return;
        }

        $columnSupport = $this->getSessionMetadataColumnSupport();
        $sessionData = $this->parseSessionDeviceInfo($request->userAgent(), $platformHint);

        $updates = [];
        if ($columnSupport['device_name']) $updates['device_name'] = $sessionData['device_name'];
        if ($columnSupport['platform']) $updates['platform'] = $sessionData['platform'];
        if ($columnSupport['ip_address']) $updates['ip_address'] = $request->ip();
        if ($columnSupport['user_agent']) $updates['user_agent'] = Str::limit((string) $request->userAgent(), 65000, '');

        if (empty($updates)) {
            return;
        }

        PersonalAccessToken::query()
            ->where('id', $currentToken->id)
            ->update($updates);
    }

     public function getSellerSlug(Request $request) {
        try {
             $sellers = user::select('id','updated_at')
                ->whereNull('deleted_at')
                ->get();

            if ($sellers->isEmpty()) {
                return ResponseService::errorResponse(__('No active seller found.'));
            }

            return ResponseService::successResponse(__('Active Seller fetched successfully.'), $sellers);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> getCategoriesSlug');
            ResponseService::errorResponse();
        }
    }

    private function resolveCampaignBadgeOption($rawCampaignBadgeKey): ?array
    {
        $candidateKey = trim((string) ($rawCampaignBadgeKey ?? ''));
        if ($candidateKey === '') {
            return null;
        }

        $config = ListingCampaignBadgeService::getConfig();
        if (!($config['enabled'] ?? false)) {
            ResponseService::validationError('Sezonske oznake oglasa su trenutno isključene.');
        }
        if (empty($config['options'])) {
            ResponseService::validationError('Sezonske oznake nisu konfigurisane. Kontaktirajte administratora.');
        }

        $resolvedOption = ListingCampaignBadgeService::resolveOptionByKey($candidateKey, $config);
        if (!$resolvedOption) {
            ResponseService::validationError('Odabrana sezonska oznaka nije dozvoljena.');
        }

        return $resolvedOption;
    }


    /**
     * Moves a temp media (stored on the configured filesystem disk) to a permanent folder.
     * Returns the NEW storage path.
     */
    private function ensureStorySlotAvailable(int $userId, ?int $ignoreItemId = null): void
    {
        if (!Schema::hasColumn('items', 'add_video_to_story')) {
            return;
        }

        $query = Item::query()
            ->where('user_id', $userId)
            ->where('add_video_to_story', 1);

        if (!empty($ignoreItemId)) {
            $query->where('id', '!=', $ignoreItemId);
        }

        if ($query->count() >= 5) {
            ResponseService::validationError('Maksimalno 5 aktivnih story objava je dozvoljeno po profilu.');
        }
    }

    private function moveTempMediaToPermanent(string $tempPath, string $destFolder): string
    {
        $diskName = config('filesystems.default');
        $disk = Storage::disk($diskName);

        if (!$disk->exists($tempPath)) {
            throw new \RuntimeException('Temp file not found: ' . $tempPath);
        }

        $ext = pathinfo($tempPath, PATHINFO_EXTENSION);
        $fileName = (string) Str::uuid() . ($ext ? ('.' . $ext) : '');
        $destFolder = trim($destFolder, '/');
        $newPath = $destFolder . '/' . $fileName;

        // Works for local + s3 (copy+delete)
        $disk->move($tempPath, $newPath);

        return $newPath;
    }

    private function storagePublicUrl(?string $path): ?string
    {
        if (!$path) return null;
        return Storage::disk(config('filesystems.default'))->url($path);
    }

    private function decodeJsonArrayInput(Request $request, string $key, array $default = []): array
    {
        $rawValue = $request->input($key, null);

        if ($rawValue === null || $rawValue === '') {
            return $default;
        }

        if (is_array($rawValue)) {
            return $rawValue;
        }

        try {
            $decoded = json_decode(html_entity_decode((string) $rawValue), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $th) {
            ResponseService::validationError("Neispravan format polja '{$key}'. Osvježite stranicu i pokušajte ponovo.");
        }

        return is_array($decoded) ? $decoded : $default;
    }

    private function getLocationLookupRateLimitConfig(string $scope): array
    {
        $scope = trim(Str::lower($scope));

        $defaultByScope = [
            'search' => ['max_attempts' => 45, 'decay_seconds' => 60],
            'coordinates' => ['max_attempts' => 120, 'decay_seconds' => 60],
            'place_id' => ['max_attempts' => 80, 'decay_seconds' => 60],
            'generic' => ['max_attempts' => 60, 'decay_seconds' => 60],
        ];

        $selected = $defaultByScope[$scope] ?? $defaultByScope['generic'];
        $maxAttempts = (int) env('LOCATION_LOOKUP_RATE_LIMIT_MAX', $selected['max_attempts']);
        $decaySeconds = (int) env('LOCATION_LOOKUP_RATE_LIMIT_DECAY_SECONDS', $selected['decay_seconds']);

        return [
            'max_attempts' => max(1, $maxAttempts),
            'decay_seconds' => max(1, $decaySeconds),
        ];
    }

    private function buildLocationRateLimitKey(Request $request, string $scope): string
    {
        $scope = trim(Str::lower($scope));
        $userId = optional($request->user())->id;
        $actor = $userId ? 'user:' . (int) $userId : 'ip:' . (string) $request->ip();

        return 'location_lookup:' . $scope . ':' . $actor;
    }

    private function resolveLocationLookupCacheTtlSeconds(string $scope): int
    {
        $scope = trim(Str::lower($scope));
        $defaultByScope = [
            'search' => 120,
            'coordinates' => 300,
            'place_id' => 300,
            'generic' => 120,
        ];

        $defaultTtl = $defaultByScope[$scope] ?? $defaultByScope['generic'];
        $ttl = (int) env('LOCATION_LOOKUP_CACHE_TTL_SECONDS', $defaultTtl);
        return max(10, $ttl);
    }

    private function buildLocationLookupCacheKey(array $payload): string
    {
        ksort($payload);
        return 'location_lookup:' . sha1(json_encode($payload));
    }

    private function resolveReverseLocationViaNominatim(float $lat, float $lng, string $lang = 'en'): ?array
    {
        try {
            $response = Http::timeout(4)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'LMX-Web-LocationResolver/1.0',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'zoom' => 12,
                    'lat' => $lat,
                    'lon' => $lng,
                    'accept-language' => trim((string) $lang) ?: 'en',
                ]);
        } catch (\Throwable $th) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $result = $response->json();
        if (!is_array($result)) {
            return null;
        }

        $address = is_array($result['address'] ?? null) ? $result['address'] : [];
        $city = collect([
            $address['city'] ?? null,
            $address['town'] ?? null,
            $address['village'] ?? null,
            $address['municipality'] ?? null,
            $address['county'] ?? null,
            $address['state_district'] ?? null,
        ])->filter(static fn ($value) => !empty($value))->first();

        $state = collect([
            $address['state'] ?? null,
            $address['region'] ?? null,
            $address['state_district'] ?? null,
            $address['county'] ?? null,
        ])->filter(static fn ($value) => !empty($value))->first();

        $country = trim((string) ($address['country'] ?? 'Bosna i Hercegovina'));
        if ($city === null && $state === null) {
            return null;
        }

        $resolvedCity = (string) ($city ?: $state ?: 'Bosna i Hercegovina');
        $resolvedState = $state ? (string) $state : null;

        return [
            'city_id' => null,
            'city' => $resolvedCity,
            'city_translation' => $resolvedCity,
            'state' => $resolvedState,
            'state_translation' => $resolvedState,
            'country' => $country,
            'country_translation' => $country,
            'area_id' => null,
            'area' => null,
            'area_translation' => null,
            'latitude' => $lat,
            'longitude' => $lng,
            'display_name' => $result['display_name'] ?? null,
        ];
    }

    private function parseCoordinateValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function isValidCoordinatePair($lat, $lng): bool
    {
        $latNum = $this->parseCoordinateValue($lat);
        $lngNum = $this->parseCoordinateValue($lng);
        if ($latNum === null || $lngNum === null) {
            return false;
        }
        if ($latNum < -90 || $latNum > 90 || $lngNum < -180 || $lngNum > 180) {
            return false;
        }
        if (abs($latNum) < 0.0001 && abs($lngNum) < 0.0001) {
            return false;
        }
        return true;
    }

    private function getDefaultListingCoordinates(): array
    {
        $defaultLat = Setting::query()->where('name', 'default_latitude')->value('value');
        $defaultLng = Setting::query()->where('name', 'default_longitude')->value('value');

        return [
            'lat' => is_numeric($defaultLat) ? (float) $defaultLat : 43.8563,
            'lng' => is_numeric($defaultLng) ? (float) $defaultLng : 18.4131,
        ];
    }

    private function normalizeLocationToken(?string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }

    private function coordinateDistanceMeters(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function resolveCoordinatesFromBihMunicipality(array $candidateNames): ?array
    {
        $tokens = collect($candidateNames)
            ->map(fn ($token) => $this->normalizeLocationToken($token))
            ->filter(fn ($token) => $token !== '')
            ->unique()
            ->values()
            ->all();

        foreach ($tokens as $token) {
            $exact = BihMunicipality::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($token)])
                ->first();

            if ($exact && $this->isValidCoordinatePair($exact->latitude, $exact->longitude)) {
                return [
                    'lat' => (float) $exact->latitude,
                    'lng' => (float) $exact->longitude,
                ];
            }
        }

        foreach ($tokens as $token) {
            $partial = BihMunicipality::query()
                ->where('name', 'like', "%{$token}%")
                ->orderBy('is_popular', 'desc')
                ->first();

            if ($partial && $this->isValidCoordinatePair($partial->latitude, $partial->longitude)) {
                return [
                    'lat' => (float) $partial->latitude,
                    'lng' => (float) $partial->longitude,
                ];
            }
        }

        return null;
    }

    private function resolveCoordinatesFromCityTable(string $cityName, string $stateName = ''): ?array
    {
        if ($cityName === '') {
            return null;
        }

        $query = City::query()->whereRaw('LOWER(name) = ?', [Str::lower($cityName)]);
        if ($stateName !== '') {
            $query->whereHas('state', function ($stateQuery) use ($stateName) {
                $stateQuery->whereRaw('LOWER(name) = ?', [Str::lower($stateName)]);
            });
        }

        $city = $query->first();
        if (!$city) {
            $city = City::query()->where('name', 'like', "%{$cityName}%")->first();
        }

        if ($city && $this->isValidCoordinatePair($city->latitude, $city->longitude)) {
            return [
                'lat' => (float) $city->latitude,
                'lng' => (float) $city->longitude,
            ];
        }

        return null;
    }

    private function resolveCoordinatesViaNominatim(array $queries): ?array
    {
        $sanitizedQueries = collect($queries)
            ->map(fn ($query) => $this->normalizeLocationToken($query))
            ->filter(fn ($query) => $query !== '')
            ->unique()
            ->take(5)
            ->values()
            ->all();

        if (empty($sanitizedQueries)) {
            return null;
        }

        foreach ($sanitizedQueries as $query) {
            try {
                $response = Http::timeout(4)
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'LMX-Web-LocationResolver/1.0',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'countrycodes' => 'ba',
                        'limit' => 5,
                        'q' => $query,
                    ]);
            } catch (\Throwable $th) {
                continue;
            }

            if (!$response->successful()) {
                continue;
            }

            $results = $response->json();
            if (!is_array($results) || empty($results)) {
                continue;
            }

            foreach ($results as $result) {
                $lat = $this->parseCoordinateValue($result['lat'] ?? null);
                $lng = $this->parseCoordinateValue($result['lon'] ?? ($result['lng'] ?? null));
                if ($this->isValidCoordinatePair($lat, $lng)) {
                    return ['lat' => (float) $lat, 'lng' => (float) $lng];
                }
            }
        }

        return null;
    }

    private function buildCoordinateSearchQueries(Request $request): array
    {
        $address = $this->normalizeLocationToken($request->input('address'));
        $city = $this->normalizeLocationToken($request->input('city'));
        $state = $this->normalizeLocationToken($request->input('state'));
        $country = $this->normalizeLocationToken($request->input('country'));

        $queries = [
            $address,
            implode(', ', array_filter([$city, $state, $country])),
            implode(', ', array_filter([$city, $country])),
            implode(', ', array_filter([$state, $country])),
            $city,
            $state,
        ];

        return array_values(array_filter(array_unique($queries)));
    }

    private function resolveListingCoordinates(Request $request, ?Item $existingItem = null): array
    {
        $directCandidates = [
            [$request->input('location_latitude'), $request->input('location_longitude')],
            [$request->input('location_lat'), $request->input('location_lng')],
            [$request->input('latitude'), $request->input('longitude')],
            [$request->input('lat'), $request->input('lng')],
            [$request->input('lat'), $request->input('long')],
        ];

        foreach ($directCandidates as [$latRaw, $lngRaw]) {
            if ($this->isValidCoordinatePair($latRaw, $lngRaw)) {
                return [
                    'lat' => (float) $this->parseCoordinateValue($latRaw),
                    'lng' => (float) $this->parseCoordinateValue($lngRaw),
                ];
            }
        }

        $areaId = $request->input('area_id');
        if (!empty($areaId)) {
            $area = Area::query()->find($areaId);
            if ($area && $this->isValidCoordinatePair($area->latitude, $area->longitude)) {
                return [
                    'lat' => (float) $area->latitude,
                    'lng' => (float) $area->longitude,
                ];
            }
        }

        $cityName = $this->normalizeLocationToken($request->input('city'));
        $stateName = $this->normalizeLocationToken($request->input('state'));
        $address = $this->normalizeLocationToken($request->input('address'));
        $addressPrimaryToken = '';
        if ($address !== '') {
            $addressPrimaryToken = $this->normalizeLocationToken(explode(',', $address)[0] ?? '');
        }

        $municipalityCoordinates = $this->resolveCoordinatesFromBihMunicipality([
            $cityName,
            $stateName,
            $addressPrimaryToken,
        ]);
        if ($municipalityCoordinates) {
            return $municipalityCoordinates;
        }

        $cityCoordinates = $this->resolveCoordinatesFromCityTable($cityName, $stateName);
        if ($cityCoordinates) {
            return $cityCoordinates;
        }

        $nominatimCoordinates = $this->resolveCoordinatesViaNominatim(
            $this->buildCoordinateSearchQueries($request)
        );
        if ($nominatimCoordinates) {
            return $nominatimCoordinates;
        }

        if (
            $existingItem &&
            $this->isValidCoordinatePair(
                $existingItem->getRawOriginal('latitude'),
                $existingItem->getRawOriginal('longitude')
            )
        ) {
            return [
                'lat' => (float) $existingItem->getRawOriginal('latitude'),
                'lng' => (float) $existingItem->getRawOriginal('longitude'),
            ];
        }

        return $this->getDefaultListingCoordinates();
    }

    private function normalizeLocationSourceValue($value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }

    private function requestHasAnyValidCoordinatePair(Request $request): bool
    {
        $pairs = [
            [$request->input('location_latitude'), $request->input('location_longitude')],
            [$request->input('location_lat'), $request->input('location_lng')],
            [$request->input('latitude'), $request->input('longitude')],
            [$request->input('lat'), $request->input('lng')],
            [$request->input('lat'), $request->input('long')],
        ];

        foreach ($pairs as [$latRaw, $lngRaw]) {
            if ($this->isValidCoordinatePair($latRaw, $lngRaw)) {
                return true;
            }
        }

        return false;
    }

    private function repairItemCoordinatesIfFallback(Item $item): void
    {
        $currentLat = $this->parseCoordinateValue($item->getRawOriginal('latitude') ?? $item->latitude);
        $currentLng = $this->parseCoordinateValue($item->getRawOriginal('longitude') ?? $item->longitude);

        $resolverRequest = new Request([
            'city' => $item->city,
            'state' => $item->state,
            'country' => $item->country,
            'address' => $item->address,
            'area_id' => $item->area_id,
        ]);
        $resolved = $this->resolveListingCoordinates($resolverRequest, $item);
        if (!$this->isValidCoordinatePair($resolved['lat'] ?? null, $resolved['lng'] ?? null)) {
            return;
        }

        if ($this->isValidCoordinatePair($currentLat, $currentLng)) {
            $distance = $this->coordinateDistanceMeters(
                (float) $currentLat,
                (float) $currentLng,
                (float) $resolved['lat'],
                (float) $resolved['lng']
            );

            $defaultCoords = $this->getDefaultListingCoordinates();
            $distanceFromDefault = $this->coordinateDistanceMeters(
                (float) $currentLat,
                (float) $currentLng,
                (float) $defaultCoords['lat'],
                (float) $defaultCoords['lng']
            );

            $looksLikeDefaultFallback = $distanceFromDefault < 3500;
            if (!$looksLikeDefaultFallback || $distance < 10000) {
                return;
            }
        }

        $item->forceFill([
            'latitude' => (float) $resolved['lat'],
            'longitude' => (float) $resolved['lng'],
        ])->saveQuietly();
        $item->setAttribute('latitude', (float) $resolved['lat']);
        $item->setAttribute('longitude', (float) $resolved['lng']);
    }


    // ======================================================
    // TEMP MEDIA UPLOAD (upload on select)
    // ======================================================
    public function uploadTempImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240', // 10MB
        ]);

        $user = Auth::user();

        // Store in temp folder, but STILL compress + watermark
        $path = FileService::compressAndUploadWithWatermark(
            $request->file('image'),
            'temp_media/images'
        );

        $temp = TempMedia::create([
            'user_id' => $user?->id,
            'type'    => 'image',
            'path'    => $path,
        ]);

        return ResponseService::successResponse('Temp image uploaded', [
            'id'  => $temp->id,
            'url' => $this->storagePublicUrl($path),
        ]);
    }

    public function uploadTempVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska|max:204800', // 200MB
        ]);

        $user = Auth::user();

        // Keep video as-is (we can add server-side transcoding later). Just store with proper extension.
        $path = FileService::upload($request->file('video'), 'temp_media/videos');

        $temp = TempMedia::create([
            'user_id' => $user?->id,
            'type'    => 'video',
            'path'    => $path,
        ]);

        return ResponseService::successResponse('Temp video uploaded', [
            'id'  => $temp->id,
            'url' => $this->storagePublicUrl($path),
        ]);
    }

}
