<?php

namespace App\Services\Payment;

use Auth;
use PhonePe\PhonePe as PhonePeSDK;
use Illuminate\Support\Facades\Log;
use Exception;

class PhonePePayment implements PaymentInterface
{
        private string $clientId;
        private string $callbackUrl;
        private string $transactionId;
        private string $clientSecret;
        private string $clientVersion;
        private string $payment_mode;
        private string $merchantId;
        private string $pgUrl;

    public function __construct($clientSecret, $clientId, $addtional_data_1,$addtional_data_2, $payment_mode)
    {
        // $this->clientId = 'TEST-CITYSURFONLINE_2508';
        $this->clientId = $clientId;
        $this->callbackUrl = url('/webhook/phonePe');
        $this->transactionId = uniqid();
        $this->clientSecret = $clientSecret;
        $this->clientVersion = $addtional_data_1;
        $this->payment_mode = $payment_mode;
        $this->merchantId = $addtional_data_2;
        $this->pgUrl = ($payment_mode == "UAT") ? "https://api-preprod.phonepe.com/apis/pg-sandbox" : "https://api.phonepe.com/apis/pg";
      
    }

    /**
     * Create payment intent for PhonePe
     *
     * @param $amount
     * @param $customMetaData
     * @return array
     * @throws Exception
     */
     public function createPaymentIntent($amount, $customMetaData) {
        Log::info("PhonePe Payment custome", [
                'amount' => $amount,
                'customMetaData' => $customMetaData,
            ]);
        $amount = $this->minimumAmountValidation('INR', $amount);
        $userMobile = Auth::user()->mobile;
        $metaData = 't' . '-' . $customMetaData['payment_transaction_id'] . '-' . 'p' . '-' . $customMetaData['package_id'];

        if ($customMetaData['platform_type'] == 'web') {
            $transactionId = uniqid();
            $mode = $this->payment_mode;
            if ($mode === 'PROD') {
                $tokenUrl = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
                $orderUrl = 'https://api.phonepe.com/apis/pg/checkout/v2/sdk/order';
                $payUrl = 'https://api.phonepe.com/apis/pg/checkout/v2/pay';
            } else {
                $tokenUrl = 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';
                $orderUrl = 'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/sdk/order';
                $payUrl = 'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/pay';
            }
            $tokenCurl = curl_init();

            curl_setopt_array($tokenCurl, array(
                CURLOPT_URL => $tokenUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query([
                    'client_id' => $this->clientId,
                    'client_version' => $this->clientVersion,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ]),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/x-www-form-urlencoded'
                ),
            ));

            $response = curl_exec($tokenCurl);
            curl_close($tokenCurl);

            // Decode token response
            $tokenResponse = json_decode($response, true);
            $accessToken = $tokenResponse['access_token'] ?? null;

            if (!$accessToken) {
                dd("Failed to retrieve token", $response);
            }
            // Build JSON payload properly
            $paymentData = [
                'merchantOrderId' => $metaData,
                'amount' => (int) round($amount * 100),
                "metadata" => [
                    "package_id" => $customMetaData['package_id'],
                    "payment_transaction_id" => $customMetaData['payment_transaction_id'],
                    "user_id" => Auth::user()->id,
                ],
                'paymentFlow' => [
                    'type' => 'PG_CHECKOUT',
                    'message' => 'Payment message used for collect requests',
                    'merchantUrls' => [
                        'redirectUrl' => route('phonepe.success.web'),
                    ],
                ],
            ];

            $payCUrl = curl_init();

            curl_setopt_array($payCUrl, array(
                CURLOPT_URL => $payUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: O-Bearer ' . $accessToken,
                ),
            ));

            $response = curl_exec($payCUrl);
            $final_response = json_decode($response, true);
            if (!empty($final_response)) {

                $redirectURL =  $final_response['redirectUrl'];
                return $this->formatPaymentIntent($transactionId, $amount, 'INR', 'pending', $customMetaData, $redirectURL);
            }
            curl_close($payCUrl);
        } else {
            $redirectUrl = route('phonepe.success');
            $orderId = 'TX' . time(); // unique order ID
            $amount = (int) round($amount * 100); // amount in INR (not multiplied)
            $expireAfter = 1200; // in seconds (20 mins)
            $token = $this->getPhonePeToken();
            $order = $this->createOrder($token, $orderId, $amount);
            $order_data = json_decode($order, true);
            $requestPayload = [
                "orderId" => $order_data['orderId'],
                // "state"  => "PENDING",
                'merchantOrderId' => $metaData,
                "merchantId" => $this->merchantId,
                "expireAT" => $expireAfter,
                "token" => $order_data['token'],
                "paymentMode" => [
                    "type" => "PAY_PAGE"
                ]
            ];

            // Convert to JSON string as required by Flutter SDK
            $requestString = json_encode($requestPayload);

            if ($this->payment_mode == "UAT") {
                $payment_mode = "SANDBOX";
            } else {
                $payment_mode = "PRODUCTION";
            }

            return [
                "environment" => $payment_mode, // or "PRODUCTION"
                "merchantId" => $this->merchantId,
                "flowId" => $orderId,
                "enableLogging" => true, // false in production
                "request" => $requestPayload,
                "appSchema" => "eclassify", // for iOS deep link return
                "token" => $token,
            ];
        }
        throw new Exception("Error initiating payment: " . $redirectURL);
    }

    /**
     * Create and format payment intent for PhonePe
     *
     * @param $amount
     * @param $customMetaData
     * @return array
     * @throws Exception
     */
    public function createAndFormatPaymentIntent($amount, $customMetaData): array
    {
        $paymentIntent = $this->createPaymentIntent($amount, $customMetaData);
        $metaData = 't' . '-' . $customMetaData['payment_transaction_id'] . '-' . 'p' . '-' . $customMetaData['package_id'];
        return $this->formatPaymentIntent(
            id: $metaData,
            amount: $amount,
            currency: 'INR',
            status: "PENDING",
            metadata: $customMetaData,
            paymentIntent: $paymentIntent
        );
    }

    /**
     * Retrieve payment intent (check payment status)
     *
     * @param $transactionId
     * @return array
     * @throws Exception
     */
    public function retrievePaymentIntent($transactionId): array
    {
        $statusUrl = 'https://api.phonepe.com/v3/transaction/' . $transactionId . '/status';
        $signature = $this->generateSignature(''); // Adjust if needed based on PhonePe requirements

        $response = $this->sendRequest($statusUrl, '', $signature);

        if ($response['success']) {
            return $this->formatPaymentIntent($transactionId, $response['amount'], 'INR', $response['status'], [], $response);
        }

        throw new Exception("Error fetching payment status: " . $response['message']);
    }

    /**
     * Format payment intent response
     *
     * @param $id
     * @param $amount
     * @param $currency
     * @param $status
     * @param $metadata
     * @param $paymentIntent
     * @return array
     */
    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array
    {
        return [
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'status' => match ($status) {
                "SUCCESS" => "succeeded",
                "PENDING" => "pending",
                "FAILED" => "failed",
                default => "unknown"
            },
            'payment_gateway_response' => $paymentIntent
        ];
    }

    /**
     * Minimum amount validation
     *
     * @param $currency
     * @param $amount
     * @return float|int
     */
    public function minimumAmountValidation($currency, $amount)
    {
        $minimumAmount = match ($currency) {
            'INR' => 1.00, // 1 Rupee
            default => 0.50
        };

        return ($amount >= $minimumAmount) ? $amount : $minimumAmount;
    }

    /**
     * Generate HMAC signature for PhonePe
     *
     * @param $encodedRequestBody
     * @return string
     */
    private function generateSignature($requestBody): string
    {
        // Concatenate raw JSON payload, endpoint, and salt key
        $stringToHash = $requestBody . '/pg/v1/pay' . $this->saltKey;

        // Hash the string using SHA256
        $hash = hash('sha256', $stringToHash);

        // Append salt index (Assumed to be 1 in this example)
        return $hash . '###' . 1;
    }

    /**
     * Send cURL request to PhonePe API
     *
     * @param $url
     * @param $requestBody
     * @param $signature
     * @return array
     */
    // private function sendRequest($url, $requestBody, $signature): array
    // {
    //     // dd($requestBody);
    //     $ch = curl_init($url);
    //     curl_setopt($ch, CURLOPT_POST, 1);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //         'Content-Type: application/json',
    //         'X-VERIFY: ' . $signature,
    //     ]);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    //     $response = curl_exec($ch);
    //     curl_close($ch);
    //     return json_decode($response, true);
    // }
    
     public function getPhonePeToken() {
        $clientId =  $this->clientId;
        $clientSecret = $this->clientSecret;
        $clientVersion = $this->clientVersion;

        $postData = http_build_query([
            'client_id' => $clientId,
            'client_version' => $clientVersion,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($this->payment_mode == "UAT") {
            $url = 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';
        } else {
            $url = 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';
        }
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Curl error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData['access_token'])) {
            return $responseData['access_token'];
        }

        throw new \Exception('Failed to fetch PhonePe token. Response: ' . $response);
    }

    public function createOrder($token, $merchantOrderId, $amount) {
        $url = $this->pgUrl . '/checkout/v2/sdk/order';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "merchantOrderId" => $merchantOrderId,
            "amount" => $amount,
            "paymentFlow" => [
                "type" => "PG_CHECKOUT"
            ]
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: O-Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}
