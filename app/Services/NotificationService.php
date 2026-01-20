<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserFcmToken;
use Google\Client;
use Google\Exception;
use Illuminate\Http\Request as HttpRequest;
use RuntimeException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NotificationService {
    /**
     * @param array $registrationIDs
     * @param string|null $title
     * @param string|null $message
     * @param string $type
     * @param array $customBodyFields
     * @return string|array|bool
     */
    public static function sendFcmNotification(
    array $registrationIDs,
    string|null $title = '',
    string|null $message = '',
    string $type = "default",
    array $customBodyFields = [],
    bool $sendToAll = false
    ): string|array|bool {
    try {
        $project_id = Setting::select('value')->where('name', 'firebase_project_id')->first();
        if (empty($project_id->value)) {
            return ['error' => true, 'message' => 'FCM configurations are not configured.'];
        }
        $project_id = $project_id->value;
        $url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';

        $access_token = self::getAccessToken();
        if ($access_token['error']) {
            return $access_token;
        }
        $dataWithTitle = [
            ...$customBodyFields,
            "title" => $title,
            "body"  => $message,
            "type"  => $type,
        ];

        if ($sendToAll) {

            $data = [
                "message" => [
                    "topic" => "allUsers", // universal topic (subscribe everyone here)
                    "data"  => self::convertToStringRecursively($dataWithTitle),
                    "notification" => [
                        "title" => $title,
                        "body"  => $message
                    ]
                ]
            ];

            $encodedData = json_encode($data);
            $headers = [
                'Authorization: Bearer ' . $access_token['data'],
                'Content-Type: application/json',
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);

            $result = curl_exec($ch);
            if (!$result) {
                return ['error' => true, 'message' => 'Curl failed: ' . curl_error($ch)];
            }
            curl_close($ch);
            return ['error' => false, 'message' => "Bulk notification sent via topic", 'data' => $result];
        }

        // ✅ Case 2: Send individually (like existing code)
        $deviceInfo = UserFcmToken::with('user')
            ->select(['platform_type', 'fcm_token'])
            ->whereIn('fcm_token', $registrationIDs)
            ->whereHas('user', fn($q) => $q->where('notification', 1))
            ->get();
        $result = [];
        foreach ($registrationIDs as $registrationID) {
            $platform = $deviceInfo->first(fn($q) => $q->fcm_token == $registrationID);
            if (!$platform) continue;
            $data = [
                "message" => [
                    "token" => $registrationID,
                    "data"  => self::convertToStringRecursively($dataWithTitle),

                     "android" => [
                        "priority" => "high",
                    ],

                    "apns"  => [
                        "headers" => ["apns-priority" => "10"],
                        "payload" => [
                            "aps" => [
                                "alert" => ["title" => $title, "body" => $message],
                                "sound" => "default"
                            ]
                        ]
                    ]
                ]
            ];
            if ($platform->platform_type != 'Android') {
                $data['message']['notification'] = ["title" => $title, "body" => $message];
            }

            $encodedData = json_encode($data);
            $headers = [
                'Authorization: Bearer ' . $access_token['data'],
                'Content-Type: application/json',
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);

            $result[] = curl_exec($ch);
            curl_close($ch);
        }

        return ['error' => false, 'message' => "Individual notifications sent", 'data' => $result];
    } catch (Throwable $th) {
        throw new RuntimeException($th);
    }
}

    public static function getAccessToken() {
        try {
            $file_name = Setting::select('value')->where('name', 'service_file')->first();
            if (empty($file_name)) {
                return [
                    'error'   => true,
                    'message' => 'FCM Configuration not found'
                ];
            }
            $disk = config('filesystems.default');
            $file = $file_name->value;

            if ($disk === 'local' || $disk === 'public') {
                // LOCAL STORAGE
                $file_path = Storage::disk($disk)->path($file);

            } else {
                // S3 (or any cloud disk)
                // Download file to local temp
                $fileContent = Storage::disk($disk)->get($file);
                $file_path = storage_path('app/firebase_service.json');
                file_put_contents($file_path, $fileContent);
            }

            if (!file_exists($file_path)) {
                return [
                    'error'   => true,
                    'message' => 'FCM Service File not found'
                ];
            }
            $client = new Client();
            $client->setAuthConfig($file_path);
            $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);

            return [
                'error'   => false,
                'message' => 'Access Token generated successfully',
                'data'    => $client->fetchAccessTokenWithAssertion()['access_token']
            ];

        } catch (Exception $e) {
            throw new RuntimeException($e);
        }
    }

    public static function convertToStringRecursively($data, &$flattenedArray = []) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::convertToStringRecursively($value, $flattenedArray);
            } elseif (is_null($value)) {
                $flattenedArray[$key] = '';
            } else {
                $flattenedArray[$key] = (string)$value;
            }
        }
        return $flattenedArray;
    }
   public static function sendNewDeviceLoginEmail(User $user, HttpRequest $request)
    {
        try {

            $deviceType = ucfirst($request->platform_type ?? 'Unknown');
            $ip = request()->ip();
            $loginTime = now()->format('d M Y - h:i A');

            // Fetch company name
            $companyName = Setting::where('name', 'company_name')->value('value') ?? 'Unknown';

            // Email message
            $message =
                "A new device has just logged in to your {$companyName} account.\n\n" .
                "⏰ Login Time: {$loginTime}\n\n";

            Mail::raw($message, function ($msg) use ($user, $companyName) {
                $msg->to($user->email)
                    ->from('admin@yourdomain.com', $companyName)
                    ->subject("New Device Login Detected - {$companyName}");
            });
        } catch (\Exception $e) {
            Log::error("Failed to send new device login email: " . $e->getMessage());
        }
    }


}
