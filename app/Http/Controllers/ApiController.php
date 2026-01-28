<?php

namespace App\Http\Controllers;


use App\Http\Resources\ItemCollection;
use App\Models\Area;
use App\Models\SellerSetting;
use App\Models\UserMembership;
use App\Models\BlockUser;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Chat;
use App\Models\City;
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
use App\Services\HelperService;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Validation\Rule;
use Throwable;
use Twilio\Rest\Client as TwilioRestClient;

use Illuminate\Support\Facades\Cache;


use Illuminate\Support\Str;
class ApiController extends Controller
{
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
                'country_code' => 'nullable|string',
                'flag' => 'boolean',
                'platform_type' => 'nullable|in:android,ios',
                'region_code'  => 'nullable|string'
            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $type = $request->type;
            $firebase_id = $request->firebase_id;
            $socialLogin = SocialLogin::where('firebase_id', $firebase_id)->where('type', $type)->with('user', function ($q) {
                $q->withTrashed();
            })->whereHas('user', function ($q) {
                $q->role('User');
            })->first();
            if (! empty($socialLogin->user->deleted_at)) {
                ResponseService::errorResponse(__('User is deactivated. Please Contact the administrator'));
            }
            if (empty($socialLogin)) {
                DB::beginTransaction();
                if ($request->type == 'phone') {
                    $unique['mobile'] = $request->mobile;
                } else {
                    $unique['email'] = $request->email;
                }
                $existingUser = User::withTrashed()->where($unique)->first();

                if ($existingUser && $existingUser->trashed()) {
                    // DB::rollBack();
                    ResponseService::errorResponse(__('Your account has been deactivated.'), null, config('constants.RESPONSE_CODE.DEACTIVATED_ACCOUNT'));
                }
                $user = User::updateOrCreate([...$unique], [
                    ...$request->all(),
                    'region_code' => $request->region_code ?? null,
                    'profile' => $request->hasFile('profile') ? $request->file('profile')->store('user_profile', 'public') : $request->profile,
                ]);
                SocialLogin::updateOrCreate([
                    'type' => $request->type,
                    'user_id' => $user->id,
                ], [
                    'firebase_id' => $request->firebase_id,
                ]);
                $user->assignRole('User');
                Auth::login($user);
                $auth = User::find($user->id);
                DB::commit();
            } else {
                Auth::login($socialLogin->user);
                $auth = Auth::user();
            }
            if (! $auth->hasRole('User')) {
                ResponseService::errorResponse(__('Invalid Login Credentials'), null, config('constants.RESPONSE_CODE.INVALID_LOGIN'));
            }

            if (! empty($request->fcm_id)) {
                //                UserFcmToken::insertOrIgnore(['user_id' => $auth->id, 'fcm_token' => $request->fcm_id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], ['user_id' => $auth->id, 'platform_type' => $request->platform_type, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            }
            $auth->fcm_id = $request->fcm_id;
            if (!empty($request->registration)) {
                //If registration is passed then don't create token
                $token = null;
            } else {
                $token = $auth->createToken($auth->name ?? '')->plainTextToken;
            }
            if ($auth) {
                NotificationService::sendNewDeviceLoginEmail($auth, $request);
            }
            ResponseService::successResponse(__('User logged-in successfully'), $auth, ['token' => $token]);
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> Signup');
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
                // 'mobile'                => 'nullable|unique:users,mobile,' . Auth::user()->id,
                 'mobile'                => [
                                                'nullable',
                                                Rule::unique('users')->ignore(Auth::user()->id)->where(function ($query) use ($request) {
                                                    return $query->where('country_code', "+".$request->country_code);
                                                }),
                                            ],
                'fcm_id'                => 'nullable',
                'address'               => 'nullable',
                'show_personal_details' => 'boolean',
                'country_code' => 'nullable|string',
                'region_code' =>  'nullable|string',
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
            'latitude' => 'required',
            'longitude' => 'required',
            'address' => 'required',
            'contact' => 'numeric',
            'show_only_to_premium' => 'nullable|boolean',
            'video_link' => 'nullable|url',
            'gallery_images' => 'nullable|array|min:1',
            'gallery_images.*' => 'nullable|mimes:jpeg,png,jpg|max:7168',
            'image' => 'required|mimes:jpeg,png,jpg|max:7168',
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
        ]);

        // Zakazana objava - MORA biti prije kreiranje itema
        if ($request->has('scheduled_at') && !empty($request->scheduled_at)) {
            $data['scheduled_at'] = $request->scheduled_at;
            $data['status'] = 'scheduled';
        }

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        if ($request->has('inventory_count') && $request->inventory_count !== null) {
            $item->inventory_count = (int) $request->inventory_count;
        }

        // 🔹 Validacija translations
        $translations = json_decode($request->input('translations', '{}'), true, 512, JSON_THROW_ON_ERROR);
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

        // 🔹 Kreiranje item-a
        $item = Item::create($data);

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
            foreach (json_decode($request->custom_fields, true, 512, JSON_THROW_ON_ERROR) as $key => $custom_field) {
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
            $customFieldTranslations = $request->input('custom_field_translations');

            if (!is_array($customFieldTranslations)) {
                $customFieldTranslations = html_entity_decode($customFieldTranslations);
                $customFieldTranslations = json_decode($customFieldTranslations, true, 512, JSON_THROW_ON_ERROR);
            }

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
    ]);
 
    if ($validator->fails()) {
        ResponseService::validationError($validator->errors()->first());
    }
    try {
        //TODO : need to simplify this whole module
        $sql = Item::with('user:id,name,email,mobile,profile,avatar_key,use_svg_avatar,created_at,is_verified,show_personal_details,country_code', 'category:id,name,image,is_job_category,price_optional',
            'gallery_images:id,image,item_id', 'featured_items', 'favourites', 'item_custom_field_values.custom_field.translations', 'area:id,name', 'job_applications', 'translations')
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
                $sql->where('status', 'approved')->has('featured_items')->getNonExpiredItems();
            } elseif ($request->status == 'expired') {
                $sql->whereNotNull('expiry_date')
                    ->where('expiry_date', '<', Carbon::now())->whereNull('deleted_at');
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
 
                'featured_ads' => $sql->where('status', 'approved')->has('featured_items')->getNonExpiredItems(),
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
        
        
 
        // Handle location-based search with fallback logic
        // Priority: area_id > city > state > country > latitude/longitude
        // Only fallback to all items if current_page=home is passed
        $isHomePage = $request->current_page === 'home';
        // Save base query before location filters for fallback
        $baseQueryBeforeLocation = clone $sql;
        $locationMessage = null;
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
            $areaItemCount = $areaQuery->count();
 
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
            $cityItemCount = $cityQuery->count();
 
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
            $stateItemCount = $stateQuery->count();
 
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
            $countryItemCount = $countryQuery->count();
 
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
            $exactLocationCount = $exactLocationQuery->count();
 
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
                $nearbyItemCount = $nearbyQuery->count();
 
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
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        DB::beginTransaction();

        try {

            $item = Item::owner()->findOrFail($request->id);
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
            $data['slug'] = $uniqueSlug;
            $data['status'] = $status;
            // image: upload file OR temp_main_image_id
            $tempMainImageId = $request->input('temp_main_image_id');
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

            $item->update($data);
            // Update or create item translations
            $translations = json_decode($request->input('translations', '{}'), true, 512, JSON_THROW_ON_ERROR);
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
                foreach (json_decode($request->custom_fields, true, 512, JSON_THROW_ON_ERROR) as $key => $custom_field) {
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
                $customFieldTranslations = $request->input('custom_field_translations');

                if (! is_array($customFieldTranslations)) {
                    $customFieldTranslations = html_entity_decode($customFieldTranslations);
                    $customFieldTranslations = json_decode($customFieldTranslations, true, 512, JSON_THROW_ON_ERROR);
                }
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
        ]);
    
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
    
        try {
            $sql = Category::withCount([
                    'subcategories' => function ($q) {
                        $q->where('status', 1);
                    },
                ])
                ->with('translations')
                ->where(['status' => 1])
                ->orderBy('sequence', 'ASC')
                ->with([
                    'subcategories' => function ($query) {
                        $query->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->with('translations')
                            ->withCount([
                                'approved_items',
                                'subcategories' => function ($q) {
                                    $q->where('status', 1);
                                },
                            ]);
                    },
    
                    'subcategories.subcategories' => function ($query) {
                        $query->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->with('translations')
                            ->withCount([
                                'approved_items',
                                'subcategories' => function ($q) {
                                    $q->where('status', 1);
                                },
                            ]);
                    },
    
                    'subcategories.subcategories.subcategories' => function ($query) {
                        $query->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->with('translations')
                            ->withCount([
                                'approved_items',
                                'subcategories' => function ($q) {
                                    $q->where('status', 1);
                                },
                            ]);
                    },
    
                    'subcategories.subcategories.subcategories.subcategories' => function ($query) {
                        $query->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->with('translations')
                            ->withCount([
                                'approved_items',
                                'subcategories' => function ($q) {
                                    $q->where('status', 1);
                                },
                            ]);
                    },
    
                    'subcategories.subcategories.subcategories.subcategories.subcategories' => function ($query) {
                        $query->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->with('translations')
                            ->withCount([
                                'approved_items',
                                'subcategories' => function ($q) {
                                    $q->where('status', 1);
                                },
                            ]);
                    },
                ]);
    
            if (!empty($request->category_id)) {
                $sql = $sql->where('parent_category_id', $request->category_id);
            } elseif (!empty($request->slug)) {
                $parentCategory = Category::where('slug', $request->slug)->firstOrFail();
                $sql = $sql->where('parent_category_id', $parentCategory->id);
            } else {
                $sql = $sql->whereNull('parent_category_id');
            }
    
            $sql = $sql->paginate();
    
            $sql->map(function ($category) {
                $category->all_items_count = $category->all_items_count;
                return $category;
            });
    
            ResponseService::successResponse(null, $sql, [
                'self_category' => $parentCategory ?? null,
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


    public function makeFeaturedItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::commit();
            $user = Auth::user();
            Item::where('status', 'approved')->findOrFail($request->item_id);
            $user_package = UserPurchasedPackage::onlyActive()
                ->where(['user_id' => $user->id])
                ->with('package')
                ->whereHas('package', function ($q) {
                    $q->where(['type' => 'advertisement']);
                })
                ->first();

            if (! $user_package) {
                return ResponseService::errorResponse(__('You need to purchase a Featured Ad plan first.'));
            }
            $featuredItems = FeaturedItems::where(['item_id' => $request->item_id, 'package_id' => $user_package->package_id])->first();
            if (! empty($featuredItems)) {
                ResponseService::errorResponse(__('Advertisement is already featured'));
            }

            $user_package->used_limit++;
            $user_package->save();

            FeaturedItems::create([
                'item_id' => $request->item_id,
                'package_id' => $user_package->package_id,
                'user_purchased_package_id' => $user_package->id,
                'start_date' => date('Y-m-d'),
                'end_date' => $user_package->end_date,
            ]);

            DB::commit();
            ResponseService::successResponse(__('Featured Advertisement Created Successfully'));
        } catch (Throwable $th) {
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> createAdvertisement');
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

            // Pre-process location filters once (outside the loop)
            // Priority: area_id > city > state > country > latitude/longitude
            // Only fallback to all items if current_page=home is passed
            $isHomePage = $request->current_page === 'home';
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
                    'featured_ads' => $baseItems->has('featured_items')->orderBy('id', 'DESC'),
                };

                // Add auth-specific relationships
                if (Auth::check()) {
                    $items->with(['item_offers' => function ($q) {
                        $q->where('buyer_id', Auth::user()->id);
                    }, 'user_reports' => function ($q) {
                        $q->where('user_id', Auth::user()->id);
                    }]);
                }

                // Limit results early and get items
                $items = $items->limit(5)->get();

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
                        Chat::create([
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
                        Chat::create([
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
                'unread_count' => Chat::where('item_offer_id', $itemOffer->id)->where('is_read', 0)->count(),
            ];

            // ne diramo message_type, ali ako postoji u modelu i smeta, možeš unset kao gore
            $autoReplyDebug = NotificationService::sendFcmNotification(
                $buyerTokens,
                'Message',
                $autoReplyChat->message,
                'chat',
                $autoFcmMsg
            );
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
            User::findOrFail(Auth::user()->id)->forceDelete();
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
            'name' => 'required',
            'email' => 'required|email',
            'subject' => 'required',
            'message' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            ContactUs::create($request->all());
            ResponseService::successResponse(__('Contact Us Stored Successfully'));

        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> storeContactUs');
            ResponseService::errorResponse();
        }
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


    public function renewItem(Request $request)
{
    try {
        $free_ad_listing = Setting::where('name', 'free_ad_listing')->value('value') ?? 0;

        // Validation rules
        $rules = [
            'item_id' => 'nullable|exists:items,id',
            'item_ids' => 'nullable|string', // accept comma-separated string
        ];

        if ($free_ad_listing == 0) {
            $rules['package_id'] = 'required|exists:packages,id';
        } else {
            $rules['package_id'] = 'nullable|exists:packages,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        // Normalize input to array
        $itemIds = [];

        if ($request->filled('item_id')) {
            $itemIds[] = $request->item_id;
        }

        if ($request->filled('item_ids')) {
            // Convert comma-separated string into array
            $ids = explode(',', $request->item_ids);
            $ids = array_map('trim', $ids);       // remove spaces
            $ids = array_filter($ids, 'strlen');  // remove empty values
            $itemIds = array_merge($itemIds, $ids);
        }

        if (empty($itemIds)) {
            return ResponseService::validationError(__('Please provide item_id or item_ids'));
        }

        $user = Auth::user();
        $package = null;
        $userPackage = null;

        // Fetch package if provided
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
        $results = [];

        foreach ($itemIds as $itemId) {
            $item = Item::find($itemId);

            if (!$item) {
                $results[$itemId] = [
                    'status' => 'failed',
                    'message' => __('Item not found'),
                ];
                continue;
            }

            $rawStatus = $item->getAttributes()['status'];

            if (Carbon::parse($item->expiry_date)->gt($currentDate)) {
                $results[$itemId] = [
                    'status' => 'failed',
                    'message' => __('Advertisement has not expired yet, so it cannot be renewed'),
                ];
                continue;
            }

            if ($package) {
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

            $item->status = $rawStatus;
            $item->save();

            $results[$itemId] = [
                'status' => 'success',
                'item' => $item,
            ];
        }

        // Return single item response if only one item was renewed
        if (count($itemIds) === 1) {
            $itemId = $itemIds[0];
            if ($results[$itemId]['status'] === 'success') {
                return ResponseService::successResponse(
                    __('Advertisement renewed successfully'),
                    $results[$itemId]['item']
                );
            } else {
                return ResponseService::errorResponse($results[$itemId]['message']);
            }
        }

        // Return multiple items response
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
            DB::rollBack();
            ResponseService::logErrorResponse($th, 'API Controller -> addVerificationFieldValues');
            ResponseService::errorResponse();
        }
    }

    public function sendVerificationRequest(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'verification_field' => 'sometimes|array',
                'verification_field.*' => 'sometimes',
                'verification_field_files' => 'nullable|array',
                'verification_field_files.*' => 'nullable|mimes:jpeg,png,jpg,pdf,doc|max:7168',
                'verification_field_translations' => 'nullable|json',

            ]);

            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
            DB::beginTransaction();

            $user = Auth::user();
            $verificationRequest = VerificationRequest::updateOrCreate([
                'user_id' => $user->id,
            ], ['status' => 'pending']);

            $user = auth()->user();
            if ($request->verification_field) {
                $itemCustomFieldValues = [];
                foreach ($request->verification_field as $id => $value) {
                    $itemCustomFieldValues[] = [
                        'user_id' => $user->id,
                        'verification_field_id' => $id,
                        'verification_request_id' => $verificationRequest->id,
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (count($itemCustomFieldValues) > 0) {
                    VerificationFieldValue::upsert($itemCustomFieldValues, ['user_id', 'verification_fields_id'], ['value', 'updated_at']);
                }
            }

            if ($request->verification_field_files) {
                $itemCustomFieldValues = [];
                foreach ($request->verification_field_files as $fieldId => $file) {
                    $itemCustomFieldValues[] = [
                        'user_id' => $user->id,
                        'verification_field_id' => $fieldId,
                        'verification_request_id' => $verificationRequest->id,
                        'value' => ! empty($file) ? FileService::upload($file, 'verification_field_files') : '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (count($itemCustomFieldValues) > 0) {
                    VerificationFieldValue::upsert($itemCustomFieldValues, ['user_id', 'verification_field_id'], ['value', 'updated_at']);
                }
            }
            if ($request->has('verification_field_translations')) {
                $fieldTranslations = json_decode($request->input('verification_field_translations'), true, 512, JSON_THROW_ON_ERROR);
                $translatedEntries = [];

                foreach ($fieldTranslations as $languageId => $fieldsById) {
                    foreach ($fieldsById as $fieldId => $translatedValue) {
                        $translatedEntries[] = [
                            'user_id' => $user->id,
                            'verification_field_id' => $fieldId,
                            'verification_request_id' => $verificationRequest->id,
                            'language_id' => $languageId,
                            'value' => is_array($translatedValue) ? implode(',', $translatedValue) : $translatedValue,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if (! empty($translatedEntries)) {
                    // upsert to avoid duplicates — if necessary
                    VerificationFieldValue::upsert(
                        $translatedEntries,
                        ['user_id', 'verification_field_id'],
                        ['value', 'updated_at', 'language_id']
                    );
                }
            }

            DB::commit();

            ResponseService::successResponse(__('Verification request submitted successfully.'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> SendVerificationRequest');
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
                ResponseService::errorResponse('No Request found');
            }

            $response = $verificationRequest->toArray();
            $response['verification_fields'] = [];

            // Get current language for translation
            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLanguage = Language::where('code', $contentLangCode)->first();
            $currentLangId = $currentLanguage->id ?? 1;

            foreach ($verificationRequest->verification_field_values as $verificationFieldValue) {
                if (
                    $verificationFieldValue->relationLoaded('verification_field') &&
                    ! empty($verificationFieldValue->verification_field)
                ) {

                    // if (empty($verificationFieldValue->language_id) || $verificationFieldValue->language_id = null) {
                    //     $verificationFieldValue->language_id = $currentLangId;
                    // }

                    $field = $verificationFieldValue->verification_field;
                    $tempRow = $field->toArray();

                    $rawValue = $verificationFieldValue->value;

                    // Normalize value to array
                    $normalizedValue = [];
                    if ($field->type === 'fileinput') {
                        $normalizedValue = ! empty($rawValue) ? [url(Storage::url($rawValue))] : [];
                    } elseif (is_array($rawValue)) {
                        $normalizedValue = $rawValue;
                    } elseif (is_string($rawValue)) {
                        $decoded = json_decode($rawValue, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $normalizedValue = $decoded;
                        } else {
                            $normalizedValue = [$rawValue];
                        }
                    } elseif (! empty($rawValue)) {
                        $normalizedValue = [$rawValue];
                    }

                    // Set normalized value
                    $tempRow['value'] = array_map('trim', explode(',', $normalizedValue[0]));

                    // Set verification_field_value with normalized value
                    $tempRow['verification_field_value'] = $verificationFieldValue->toArray();
                    unset($tempRow['verification_field_value']['verification_field']);
                    $tempRow['verification_field_value']['value'] = $normalizedValue;
                    $tempRow['verification_field_value']['language_id'] = $verificationFieldValue->language_id;
                    // Handle translated_selected_values
                    $selected = [];
                    $type = $field->type ?? null;
                    $allPossibleValues = $field->values ?? [];

                    // Fetch translated values (if available)
                    $translatedValues = [];
                    if (! empty($field->translations)) {
                        $translation = collect($field->translations)->firstWhere('language_id', $currentLangId);
                        $translatedValues = $translation['value'] ?? [];
                    }
                    if (empty($translatedValues)) {
                        $translatedValues = $allPossibleValues;
                    }

                    if (in_array($type, ['checkbox', 'radio', 'dropdown'])) {
                        foreach ($normalizedValue as $val) {
                            $index = array_search($val, $allPossibleValues);
                            $translatedVal = ($index !== false && isset($translatedValues[$index]))
                                ? $translatedValues[$index]
                                : $val;
                            $selected[] = $translatedVal;
                        }
                    } elseif (in_array($type, ['textbox', 'number'])) {
                        $selected = $normalizedValue;
                    }
                    $tempRow['language_id'] = $verificationFieldValue->language_id;
                    $tempRow['translated_selected_values'] = $selected;
                    $response['verification_fields'][] = $tempRow;
                }
            }

            ResponseService::successResponse(__('Verification request fetched successfully.'), $response);
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> SendVerificationRequest');
            ResponseService::errorResponse();
        }
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
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            // Format the phone number properly
            $requestNumber = $request->number;
            $trimmedNumber = ltrim($requestNumber, '+');
            $toNumber = '+'.$trimmedNumber;

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

            return ResponseService::successResponse(__('OTP sent successfully.'));
        } catch (Throwable $th) {
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
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $requestNumber = $request->number;
            $trimmedNumber = ltrim($requestNumber, '+');
            $toNumber = '+'.$trimmedNumber;

            $otpRecord = NumberOtp::where('number', $toNumber)->first();

            if (! $otpRecord) {
                return ResponseService::errorResponse(__('OTP not found.'));
            }
            if (now()->isAfter($otpRecord->expire_at)) {
                return ResponseService::validationError(__('OTP has expired.'));
            }

            if ($otpRecord->attempts >= 3) {
                $otpRecord->delete();

                return ResponseService::validationError(__('OTP expired after 3 failed attempts.'));
            }

            if ($otpRecord->otp != $request->otp) {
                $otpRecord->increment('attempts');

                return ResponseService::validationError(__('Invalid OTP.'));
            }
            $otpRecord->delete();

            $user = User::where('mobile', $trimmedNumber)->where('type', 'phone')->first();

            if (! $user) {
                $user = User::create([
                    'mobile' => $trimmedNumber,
                    'type' => 'phone',
                ]);

                $user->assignRole('User');
            }

            Auth::login($user);
            $auth = User::find(Auth::id());

            $token = $auth->createToken($auth->name ?? '')->plainTextToken;

            return ResponseService::successResponse(__('User logged-in successfully'), $auth, ['token' => $token]);
        } catch (Throwable $th) {
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
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'lang' => 'nullable|string',
            'search' => 'nullable|string',
            'place_id' => 'nullable|string',
            'session_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $lat = $request->lat;
            $lng = $request->lng;
            $lang = $request->lang ?? 'en';
            $search = $request->search;
            $placeId = $request->place_id;
            $mapProvider = Setting::where('name', 'map_provider')->value('value') ?? 'free_api';

            // Determine current language ID
            $contentLangCode = $request->header('Content-Language') ?? app()->getLocale();
            $currentLanguage = Language::where('code', $contentLangCode)->first();
            $currentLangId = $currentLanguage->id ?? 1;

            /**
             * 🔍 Handle search query
             */
            if ($search) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }

                    $response = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                        'key' => $apiKey,
                        'input' => $search,
                        'language' => $lang,
                        'sessiontoken' => $request->session_id, // ✅ added
                    ]);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from Google API'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API'));

                } else {
                    // Search Areas with translations
                    $areas = Area::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'city.state.country.nametranslations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('name', 'like', "%{$search}%")
                        ->limit(10)
                        ->get();

                    if ($areas->isNotEmpty()) {
                        return ResponseService::successResponse(__('Matching areas found'), $areas->map(function ($area) {
                            return [
                                'area_id' => $area->id,
                                'area' => $area->name,
                                'area_translation' => optional($area->translations->first())->name ?? $area->name,
                                'city_id' => optional($area->city)->id,
                                'city' => optional($area->city)->name,
                                'city_translation' => optional($area->city->translations->first())->name ?? optional($area->city)->name,
                                'state' => optional($area->city->state)->name,
                                'state_translation' => optional($area->city->state->translations->first())->name ?? optional($area->city->state)->name,
                                'country' => optional($area->city->state->country)->name,
                                'country_translation' => optional($area->city->state->country->nametranslations->first())->name ?? optional($area->city->state->country)->name,
                                'latitude' => $area->latitude,
                                'longitude' => $area->longitude,
                            ];
                        }));
                    }

                    // Search Cities with translations
                    $cities = City::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.country.nametranslations' => fn ($q) => $q->where('language_id', $currentLangId),
                    ])
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('state', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('state.country', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->limit(10)
                        ->get();

                    if ($cities->isEmpty()) {
                        return ResponseService::errorResponse(__('No matching location found'));
                    }

                    return ResponseService::successResponse(__('Matching cities found'), $cities->map(function ($city) {
                        return [
                            'city_id' => $city->id,
                            'city' => $city->name,
                            'city_translation' => optional($city->translations->first())->name ?? $city->name,
                            'state' => optional($city->state)->name,
                            'state_translation' => optional($city->state->translations->first())->name ?? optional($city->state)->name,
                            'country' => optional($city->state->country)->name,
                            'country_translation' => optional($city->state->country->nametranslations->first())->name ?? optional($city->state->country)->name,
                            'latitude' => $city->latitude,
                            'longitude' => $city->longitude,
                        ];
                    }));
                }
            }

            /**
             * 📍 Get location by coordinates
             */
            if (! empty($lat) && ! empty($lng)) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }

                    $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                        'latlng' => "{$lat},{$lng}",
                        'key' => $apiKey,
                        'language' => $lang,
                        'sessiontoken' => $request->session_id, // ✅ added
                    ]);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from Google API'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API'));

                } else {

                    $closestCity = City::with([
                        'translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.translations' => fn ($q) => $q->where('language_id', $currentLangId),
                        'state.country.nametranslations' => fn ($q) => $q->where('language_id', $currentLangId),
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
                        return ResponseService::errorResponse(__('No nearby city found'));
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

                    return ResponseService::successResponse(__('Location fetched from local database'), [
                        'city_id' => $closestCity->id,
                        'city' => $closestCity->name,
                        'city_translation' => optional($closestCity->translations->first())->name ?? $closestCity->name,
                        'state' => optional($closestCity->state)->name,
                        'state_translation' => optional($closestCity->state->translations->first())->name ?? optional($closestCity->state)->name,
                        'country' => optional($closestCity->state->country)->name,
                        'country_translation' => optional($closestCity->state->country->nametranslations->first())->name ?? optional($closestCity->state->country)->name,
                        'area_id' => optional($closestArea)->id,
                        'area' => optional($closestArea)->name,
                        'area_translation' => optional($closestArea?->translations?->first())->name ?? $closestArea?->name,
                        'latitude' => $closestCity->latitude,
                        'longitude' => $closestCity->longitude,
                    ]);
                }
            }

            /**
             * 🏷️ Handle place_id
             */
            if ($placeId) {
                if ($mapProvider === 'google_places') {
                    $apiKey = Setting::where('name', 'place_api_key')->value('value');
                    if (! $apiKey) {
                        return ResponseService::errorResponse(__('Google Maps API key not set'));
                    }
                    $sessionParam = $request->session_id ? "&sessiontoken={$request->session_id}" : '';
                    $url = "https://maps.googleapis.com/maps/api/geocode/json?place_id={$placeId}&key={$apiKey}&language={$lang}{$sessionParam}";

                    // $url = "https://maps.googleapis.com/maps/api/geocode/json?place_id={$placeId}&key={$apiKey}&language={$lang}";
                    $response = Http::get($url);

                    return $response->successful()
                        ? ResponseService::successResponse(__('Location fetched from place_id'), $response->json())
                        : ResponseService::errorResponse(__('Failed to fetch from Google Maps API using place_id'));
                } else {
                    return ResponseService::errorResponse(__('place_id is only supported with Google Maps provider'));
                }
            }

        } catch (\Throwable $th) {
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
            if($request->fcm_token){
                 UserFcmToken::where('user_id', $user->id)
                ->where('fcm_token', $request->fcm_token)
                ->delete();
            }
            return ResponseService::successResponse(__('User logged out successfully'));
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, 'API Controller -> Logout');

            return ResponseService::errorResponse();
        }
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


    /**
     * Moves a temp media (stored on the configured filesystem disk) to a permanent folder.
     * Returns the NEW storage path.
     */
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
