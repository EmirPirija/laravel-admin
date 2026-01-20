<?php

namespace App\Http\Controllers;

use App\Models\PaymentConfiguration;
use App\Models\Setting;
use App\Models\SettingTranslation;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ImportDummyDataJob;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Throwable;

class SettingController extends Controller
{
    private string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'settings';
    }

    public function index()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        return view('settings.index');
    }

    public function page()
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $type = last(request()->segments());
        $settings = CachingService::getSystemSettings()->toArray();
        if (!empty($settings['place_api_key']) && config('app.demo_mode')) {
            $settings['place_api_key'] = "**************************";
        }
        $stripe_currencies = ["USD", "AED", "AFN", "ALL", "AMD", "ANG", "AOA", "ARS", "AUD", "AWG", "AZN", "BAM", "BBD", "BDT", "BGN", "BIF", "BMD", "BND", "BOB", "BRL", "BSD", "BWP", "BYN", "BZD", "CAD", "CDF", "CHF", "CLP", "CNY", "COP", "CRC", "CVE", "CZK", "DJF", "DKK", "DOP", "DZD", "EGP", "ETB", "EUR", "FJD", "FKP", "GBP", "GEL", "GIP", "GMD", "GNF", "GTQ", "GYD", "HKD", "HNL", "HTG", "HUF", "IDR", "ILS", "INR", "ISK", "JMD", "JPY", "KES", "KGS", "KHR", "KMF", "KRW", "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL", "MAD", "MDL", "MGA", "MKD", "MMK", "MNT", "MOP", "MRO", "MUR", "MVR", "MWK", "MXN", "MYR", "MZN", "NAD", "NGN", "NIO", "NOK", "NPR", "NZD", "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG", "QAR", "RON", "RSD", "RUB", "RWF", "SAR", "SBD", "SCR", "SEK", "SGD", "SHP", "SLE", "SOS", "SRD", "STD", "SZL", "THB", "TJS", "TOP", "TTD", "TWD", "TZS", "UAH", "UGX", "UYU", "UZS", "VND", "VUV", "WST", "XAF", "XCD", "XOF", "XPF", "YER", "ZAR", "ZMW"];
        $languages = CachingService::getLanguages();
        $translations = $this->getSettingTranslations();

        $languages_translate = CachingService::getLanguages()->where('code', '!=', 'en')->values();
        return view('settings.' . $type, compact('settings', 'type', 'languages', 'stripe_currencies', 'languages_translate', 'translations'));
    }
    private function getSettingTranslations()
    {
        $settings = Setting::with('translations')->get();

        $translations = [];

        foreach ($settings as $setting) {
            foreach ($setting->translations as $translation) {
                $translations[$setting->name][$translation->language_id] = $translation->translated_value;
            }
        }

        return $translations;
    }
    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $validator = Validator::make($request->all(), [
            "company_name"           => "nullable",
            "company_email"          => "nullable",
            "company_tel1"           => "nullable",
            "company_tel2"           => "nullable",
            "company_address"        => "nullable",
            "default_language"       => "nullable",
            "currency_symbol"        => "nullable",
            "android_version"        => "nullable",
            "play_store_link"        => "nullable",
            "ios_version"            => "nullable",
            "app_store_link"         => "nullable",
            "maintenance_mode"       => "nullable",
            "force_update"           => "nullable",
            "number_with_suffix"     => "nullable",
            "firebase_project_id"    => "nullable",
            "service_file"           => "nullable",
            "favicon_icon"           => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            "company_logo"           => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            "login_image"            => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            // "watermark_image"        => 'nullable|mimes:jpg,jpeg,png|max:7168',
            "web_theme_color"        => "nullable",
            "place_api_key"          => "nullable",
            "header_logo"            => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            "footer_logo"            => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            "placeholder_image"      => "nullable|mimes:jpg,jpeg,png,svg|max:7168",
            "footer_description"     => "nullable",
            "google_map_iframe_link" => "nullable",
            "default_latitude"       => "nullable",
            "default_longitude"      => "nullable",
            "instagram_link"         => "nullable|url",
            "x_link"                 => "nullable|url",
            "facebook_link"          => "nullable|url",
            "linkedin_link"          => "nullable|url",
            "pinterest_link"         => "nullable|url",
            "deep_link_text_file"    => "nullable",
            "deep_link_json_file"    => "nullable|mimes:json|max:7168",
            "mobile_authentication"    => "nullable",
            "google_authentication"    => "nullable",
            "email_authentication"    => "nullable",
            "apple_authenticaion"    => "nullable",
            // Email settings validation
            "mail_mailer"            => "nullable",
            "mail_host"              => "nullable",
            "mail_port"              => "nullable",
            "mail_username"          => "nullable",
            "mail_password"          => "nullable",
            "mail_encryption"        => "nullable",
            "mail_from_address"      => "nullable|email",
            'deep_link_scheme'       => 'nullable|string|regex:/^[a-z][a-z0-9]*$/|max:30',
            "otp_service_provider"    => "nullable|in:firebase,twilio",
            "twilio_account_sid"      => "nullable",
            "twilio_auth_token"       => "nullable",
            "twilio_my_phone_number"  => "nullable",
            "admin_user_email"        => "nullable|email",
            "admin_user_password"     => "nullable",
        ]);
        if (
            $request->has('mobile_authentication') && $request->mobile_authentication == 0 &&
            $request->has('google_authentication') && $request->google_authentication == 0 &&
            $request->has('email_authentication') && $request->email_authentication == 0 &&
            $request->has('apple_authentication') && $request->apple_authentication == 0
        ) {
            ResponseService::validationError('At least one authentication method must be enabled.');
        }
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {

            $inputs = $request->input();
            // Validate email & password BEFORE creating ad
         // Validate admin user email & password
         if ($request->has('admin_user_email')) {
                $user = User::where('email', $request->admin_user_email)->first();
                if (!$user) {
                    ResponseService::errorResponse('No account found with this email.');
                }
            }


            unset($inputs['_token']);
            if (config('app.demo_mode')) {
                unset($inputs['place_api_key']);
            }
            $data = [];
            foreach ($inputs as $key => $input) {
                if (in_array($key, ['translations', 'about_us', 'languages', 'contact_us', 'privacy_policy', 'refund_policy', 'terms_conditions'])) {
                    continue;
                }
                $data[] = [
                    'name'  => $key,
                    'value' => $input,
                    'type'  => 'string'
                ];
            }
            
            $oldSettingFiles = Setting::whereIn('name', collect($request->files)->keys())->get();
            foreach ($request->files as $key => $file) {

                if (in_array($key, ['deep_link_json_file', 'deep_link_text_file'])) {
                    $filenameMap = [
                        'deep_link_json_file' => 'assetlinks.json',
                        'deep_link_text_file' => 'apple-app-site-association',
                    ];

                    $filename = $filenameMap[$key];
                    $fileContents = File::get($file);
                    $publicWellKnownPath = public_path('.well-known');
                    if (!File::exists($publicWellKnownPath)) {
                        File::makeDirectory($publicWellKnownPath, 0755, true);
                    }

                    $publicPath = public_path('.well-known/' . $filename);
                    File::put($publicPath, $fileContents);

                    $rootPath = base_path('.well-known/' . $filename);
                    File::put($rootPath, $fileContents);
                } else {

                    $data[] = [
                        'name'  => $key,
                        'value' => FileService::compressAndUpload($request->file($key), $this->uploadFolder),
                        // 'value' => $request->file($key)->store($this->uploadFolder, 'public'),
                        'type'  => 'file'
                    ];
                    $oldFile = $oldSettingFiles->first(function ($old) use ($key) {
                        return $old->name == $key;
                    });
                    if (!empty($oldFile)) {
                        FileService::delete($oldFile->getRawOriginal('value'));
                    }
                }
            }
            Setting::upsert($data, 'name', ['value']);

            if (!empty($inputs['company_name']) && config('app.name') != $inputs['company_name']) {
                HelperService::changeEnv([
                    'APP_NAME' => $inputs['company_name'],
                ]);
            }

            // Update .env file for email settings
            $emailSettings = [
                'MAIL_MAILER' => $inputs['mail_mailer'] ?? config('mail.mailer'),
                'MAIL_HOST' => $inputs['mail_host'] ?? config('mail.host'),
                'MAIL_PORT' => $inputs['mail_port'] ?? config('mail.port'),
                'MAIL_USERNAME' => $inputs['mail_username'] ?? config('mail.username'),
                'MAIL_PASSWORD' => $inputs['mail_password'] ?? config('mail.password'),
                'MAIL_ENCRYPTION' => $inputs['mail_encryption'] ?? config('mail.encryption'),
                'MAIL_FROM_ADDRESS' => $inputs['mail_from_address'] ?? config('mail.from.address'),
            ];
            $filteredSettings = array_filter($emailSettings, function ($value) {
                return !is_null($value) && $value !== '';
            });

            // Only update env if there's something to update
            if (!empty($filteredSettings)) {
                HelperService::changeEnv($filteredSettings);
            }

            if (!empty($inputs['otp_service_provider']) && $inputs['otp_service_provider'] === 'twilio') {
                HelperService::changeEnv([
                    'TWILIO_ACCOUNT_SID'   => $inputs['twilio_account_sid'] ?? config('services.twilio.account_sid'),
                    'TWILIO_AUTH_TOKEN'    => $inputs['twilio_auth_token'] ?? config('services.twilio.auth_token'),
                ]);
            }

            if ($request->has('about_us')) {
                $aboutUsInputs = $request->input('about_us', []);

                // Save default About Us (first language or fallback)
                $defaultAboutUs = reset($aboutUsInputs);
                Setting::updateOrCreate(
                    ['name' => 'about_us'],
                    ['value' => $defaultAboutUs, 'type' => 'string']
                );

                // Save translations
                foreach ($aboutUsInputs as $languageId => $value) {
                    $setting = Setting::where('name', 'about_us')->first();
                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            ['setting_id' => $setting->id, 'language_id' => $languageId],
                            ['translated_value' => $value]
                        );
                    }
                }
            }
            if ($request->has('contact_us')) {
                $contactUsInputs = $request->input('contact_us', []);

                // Save default Contact Us
                $defaultContactUs = reset($contactUsInputs);
                Setting::updateOrCreate(
                    ['name' => 'contact_us'],
                    ['value' => $defaultContactUs, 'type' => 'string']
                );

                // Save translations
                foreach ($contactUsInputs as $languageId => $value) {
                    $setting = Setting::where('name', 'contact_us')->first();
                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            ['setting_id' => $setting->id, 'language_id' => $languageId],
                            ['translated_value' => $value]
                        );
                    }
                }
            }
            if ($request->has('privacy_policy')) {
                $privacyInputs = $request->input('privacy_policy', []);

                // Save default Privacy Policy
                $defaultPrivacy = reset($privacyInputs);
                Setting::updateOrCreate(
                    ['name' => 'privacy_policy'],
                    ['value' => $defaultPrivacy, 'type' => 'string']
                );

                // Save translations
                foreach ($privacyInputs as $languageId => $value) {
                    $setting = Setting::where('name', 'privacy_policy')->first();
                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            ['setting_id' => $setting->id, 'language_id' => $languageId],
                            ['translated_value' => $value]
                        );
                    }
                }
            }
            if ($request->has('refund_policy')) {
                $refundInputs = $request->input('refund_policy', []);

                // Save default Refund Policy
                $defaultRefund = reset($refundInputs);
                Setting::updateOrCreate(
                    ['name' => 'refund_policy'],
                    ['value' => $defaultRefund, 'type' => 'string']
                );

                // Save translations
                foreach ($refundInputs as $languageId => $value) {
                    $setting = Setting::where('name', 'refund_policy')->first();
                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            ['setting_id' => $setting->id, 'language_id' => $languageId],
                            ['translated_value' => $value]
                        );
                    }
                }
            }

            if ($request->has('terms_conditions')) {
                $termsInputs = $request->input('terms_conditions', []);

                // Save default Terms & Conditions
                $defaultTerms = reset($termsInputs);
                Setting::updateOrCreate(
                    ['name' => 'terms_conditions'],
                    ['value' => $defaultTerms, 'type' => 'string']
                );

                // Save translations
                foreach ($termsInputs as $languageId => $value) {
                    $setting = Setting::where('name', 'terms_conditions')->first();
                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            ['setting_id' => $setting->id, 'language_id' => $languageId],
                            ['translated_value' => $value]
                        );
                    }
                }
            }
            if ($request->has('translations')) {
                foreach ($request->input('translations') as $languageId => $translationData) {
                    $setting = Setting::where('name', $translationData['name'])->first();

                    if ($setting) {
                        SettingTranslation::updateOrCreate(
                            [
                                'setting_id' => $setting->id,
                                'language_id' => $languageId
                            ],
                            [
                                'translated_value' => $translationData['value'] ?? null
                            ]
                        );
                    }
                }
            }
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
            ResponseService::successResponse('Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Setting Controller -> store");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }


    public function updateFirebaseSettings(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $validator = Validator::make($request->all(), [
            'apiKey'            => 'required',
            'authDomain'        => 'required',
            'projectId'         => 'required',
            'storageBucket'     => 'required',
            'messagingSenderId' => 'required',
            'appId'             => 'required',
            'measurementId'     => 'required',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $inputs = $request->input();
            unset($inputs['_token']);
            $data = [];
            foreach ($inputs as $key => $input) {
                $data[] = [
                    'name'  => $key,
                    'value' => $input,
                    'type'  => 'string'
                ];
            }
            Setting::upsert($data, 'name', ['value']);
            //Service worker file will be copied here
            File::copy(public_path('assets/dummy-firebase-messaging-sw.js'), public_path('firebase-messaging-sw.js'));
            $serviceWorkerFile = file_get_contents(public_path('firebase-messaging-sw.js'));

            $updateFileStrings = [
                "apiKeyValue"            => '"' . $request->apiKey . '"',
                "authDomainValue"        => '"' . $request->authDomain . '"',
                "projectIdValue"         => '"' . $request->projectId . '"',
                "storageBucketValue"     => '"' . $request->storageBucket . '"',
                "messagingSenderIdValue" => '"' . $request->measurementId . '"',
                "appIdValue"             => '"' . $request->appId . '"',
                "measurementIdValue"     => '"' . $request->measurementId . '"'
            ];
            $serviceWorkerFile = str_replace(array_keys($updateFileStrings), $updateFileStrings, $serviceWorkerFile);
            file_put_contents(public_path('firebase-messaging-sw.js'), $serviceWorkerFile);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
            ResponseService::successResponse('Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Settings Controller -> updateFirebaseSettings");
            ResponseService::errorResponse();
        }
    }

    public function paymentSettingsIndex()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        $paymentConfiguration = PaymentConfiguration::all();
        $paymentGateway = [];
        foreach ($paymentConfiguration as $row) {
            $paymentGateway[$row->payment_method] = $row->toArray();
        }
        $settings = CachingService::getSystemSettings()->toArray();
        return view('settings.payment-gateway', compact('paymentGateway', 'settings'));
    }

    public function paymentSettingsStore(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $validator = Validator::make($request->all(), [
            'gateway'          => 'required|array',
            'gateway.Stripe'   => 'required|array|required_array_keys:api_key,secret_key,webhook_secret_key,status',
            'gateway.Razorpay' => 'required|array|required_array_keys:api_key,secret_key,webhook_secret_key,status',
            'gateway.Paystack' => 'required|array|required_array_keys:api_key,secret_key,status',
            'gateway.PhonePe' => 'required|array|required_array_keys:secret_key,api_key,additional_data_1,username,password,payment_mode,status',
            'bank'             => 'required|array'
        ]);
        $gatewayStatuses = [
            $request->input('gateway.Stripe.status',0),
            $request->input('gateway.Razorpay.status',0),
            $request->input('gateway.Paystack.status',0),
            $request->input('gateway.PhonePe.status',0),
            $request->input('gateway.flutterwave.status',0),
             $request->input('gateway.Paypal.status',0),
            $request->input('bank.bank_transfer_status',0),
        ];
        if (!in_array('1', $gatewayStatuses, true)) {
            ResponseService::validationError('At least one payment gateway must be enabled.');
        }
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {

            foreach ($request->input('bank') as $key => $value) {
                Setting::updateOrCreate(['name' => $key], ['value' => $value]);
            }
            foreach ($request->gateway as $key => $gateway) {
                PaymentConfiguration::updateOrCreate(['payment_method' => $key], [
                    'api_key'            => $gateway["api_key"] ?? '',
                    'secret_key'         => $gateway["secret_key"] ?? '',
                    'webhook_secret_key' => $gateway["webhook_secret_key"] ?? '',
                    'status'             => $gateway["status"] ?? '',
                    'currency_code'      => $gateway["currency_code"] ?? '',
                    'additional_data_1'      => $gateway["additional_data_1"] ?? '',
                    'additional_data_2'      => $gateway["additional_data_2"] ?? '',
                    'payment_mode'      => $gateway["payment_mode"] ?? '',
                    'username'      => $gateway["username"] ?? '',
                    'password'      => $gateway["password"] ?? ''

                ]);
                if ($key === 'Paystack') {
                    HelperService::changeEnv([
                        'PAYSTACK_PUBLIC_KEY'  => $gateway['api_key'] ?? '',
                        'PAYSTACK_SECRET_KEY'  => $gateway['secret_key'] ?? '',
                        'PAYSTACK_PAYMENT_URL' => "https://api.paystack.co"
                    ]);
                }
            }
            ResponseService::successResponse('Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Settings Controller -> updateFirebaseSettings");
            ResponseService::errorResponse();
        }
    }
    // public function syatemStatusIndex() {
    //     return view('settings.system-status');
    // }
    public function toggleStorageLink()
    {
        $linkPath = public_path('storage');

        if (file_exists($linkPath)) {
            if (is_link($linkPath)) {
                if (unlink($linkPath)) {
                    return back()->with('message', 'Storage link unlinked successfully!');
                }

                return back()->with('message', 'Failed to unlink the storage link.');
            }

            return back()->with('message', 'Storage link is not a symbolic link.');
        } else {
            Artisan::call('storage:link');

            if (file_exists($linkPath) && is_link($linkPath)) {
                return back()->with('message', 'Storage link created successfully!');
            }

            return back()->with('message', 'Failed to create the storage link.');
        }
    }


    public function systemStatus()
    {
        $linkPath = public_path('storage');
        $isLinked = file_exists($linkPath) && is_dir($linkPath);

        return view('settings.system-status', compact('isLinked'));
    }

    public function fileManagerSettingStore(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        $validator = Validator::make($request->all(), [
            "file_manager"    => "required|in:public,s3",
            "S3_aws_access_key_id"    => "required_if:file_manager,==,s3",
            "s3_aws_secret_access_key"    => "required_if:file_manager,==,s3",
            "s3_aws_default_region"    => "required_if:file_manager,==,s3",
            "s3_aws_bucket"    => "required_if:file_manager,==,s3",
            "s3_aws_url"    => "required_if:file_manager,==,s3",
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $inputs = $request->input();
            $data = [];
            foreach ($inputs as $key => $input) {
                $data[] = [
                    'name'  => $key,
                    'value' => $input,
                    'type'  => 'string'
                ];
            }
            Setting::upsert($data, 'name', ['value']);

            $env = [
                'FILESYSTEM_DISK' => $inputs['file_manager'],
                'AWS_ACCESS_KEY_ID' => $inputs['S3_aws_access_key_id'] ?? null,
                'AWS_SECRET_ACCESS_KEY' => $inputs['s3_aws_secret_access_key'] ?? null,
                'AWS_DEFAULT_REGION' => $inputs['s3_aws_default_region'] ?? null,
                'AWS_BUCKET' => $inputs['s3_aws_bucket'] ?? null,
                'AWS_URL' => $inputs['s3_aws_url'] ?? null,
            ];

            HelperService::changeEnv($env);
            ResponseService::successResponse('File Manager Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th, "Setting Controller -> fileManagerSettingStore");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }
    public function paystackPaymentSucesss()
    {
        return view('payment.paystack');
    }
    public function phonepePaymentSucesss()
    {
        return view('payment.phonepe');
    }
    public function webPageURL($slug)
    {
        $appStoreLink = CachingService::getSystemSettings('app_store_link');
        $playStoreLink = CachingService::getSystemSettings('play_store_link');
        $appName = CachingService::getSystemSettings('company_name');
        $scheme = CachingService::getSystemSettings('deep_link_scheme');
        return view('deep-link.deep_link', compact('appStoreLink', 'playStoreLink', 'appName', 'scheme'));
    }

    public function flutterWavePaymentSucesss()
    {
        return view('payment.flutterwave');
    }
    public function dummyDataIndex()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        return view('settings.dummy-data');
    }
    public function importDummyData(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-update');
        try {
            response()->json([
                'error'   => false,
                'message' => trans('⏳ Dummy data import started in background. You can continue using the panel — it will complete automatically.'),
                'data'    => null,
                'code'    => config('constants.RESPONSE_CODE.SUCCESS')
            ])->send();
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
                (new ImportDummyDataJob())->handle();
            } else {
                register_shutdown_function(function () {
                    try {
                        (new ImportDummyDataJob())->handle();
                    } catch (\Throwable $th) {
                        Log::error('Background job failed: ' . $th->getMessage());
                        Log::error('Stack trace: ' . $th->getTraceAsString());
                    }
                });
            }
            
            // Exit to prevent further execution
            exit();
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th, "SettingController -> importDummyData");
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

}
