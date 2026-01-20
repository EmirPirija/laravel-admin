<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Package;
use App\Models\PaymentConfiguration;
use App\Models\PaymentTransaction;
use App\Models\UserFcmToken;
use App\Models\UserPurchasedPackage;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PayPalPayment;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use PhonePe\PhonePe;
use Throwable;


class WebhookController extends Controller {
    public function stripe() {
        $payload = @file_get_contents('php://input');
        try {
            // Verify webhook signature and extract the event.
            // See https://stripe.com/docs/webhooks/signatures for more information.
            // $data = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);

            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

            // You can find your endpoint's secret in your webhook settings
            $paymentConfiguration = PaymentConfiguration::select('webhook_secret_key')->where('payment_method', 'Stripe')->first();
            $endpoint_secret = $paymentConfiguration['webhook_secret_key'] ;
            $event = Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );

            $metadata = $event->data->object->metadata;

            // Use this lines to Remove Signature verification for debugging purpose
            // $event = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
            // $metadata = (array)$event->data->object->metadata;

            Log::info("Stripe Webhook : ", [$event]);
            // handle the events
            switch ($event->type) {
                case 'payment_intent.created':
                    //Do nothing
                    http_response_code(200);
                    break;
                case 'payment_intent.succeeded':
                    $response = $this->assignPackage($metadata['payment_transaction_id'], $metadata['user_id'], $metadata['package_id']);

                    if ($response['error']) {
                        Log::error("Stripe Webhook : ", [$response['message']]);
                    }
                    http_response_code(200);
                    break;
                case 'payment_intent.payment_failed':
                    $response = $this->failedTransaction($metadata['payment_transaction_id'], $metadata['user_id']);
                    if ($response['error']) {
                        Log::error("Stripe Webhook : ", [$response['message']]);
                    }
                    http_response_code(400);
                    break;
                default:
                    Log::error('Stripe Webhook : Received unknown event type', [$event->type]);
            }
        } catch (UnexpectedValueException) {
            // Invalid payload
            echo "Stripe Webhook : Payload Mismatch";
            Log::error("Stripe Webhook : Payload Mismatch");
            http_response_code(400);
            exit();
        } catch (SignatureVerificationException) {
            // Invalid signature
            echo "Stripe Webhook : Signature Verification Failed";
            Log::error("Stripe Webhook : Signature Verification Failed");
            http_response_code(400);
            exit();
        } catch
        (Throwable $e) {
            Log::error("Stripe Webhook : Error occurred", [$e->getMessage() . ' --> ' . $e->getFile() . ' At Line : ' . $e->getLine()]);
            http_response_code(400);
            exit();
        }
    }

    public function razorpay() {
        try {
            $paymentConfiguration = PaymentConfiguration::select('webhook_secret_key')->where('payment_method', 'razorpay')->first();
            $webhookSecret = $paymentConfiguration['webhook_secret_key'];
            $webhookPublic = $paymentConfiguration["webhook_public_key"];

            // get the json data of payment
            $webhookBody = file_get_contents('php://input');
            $data = json_decode($webhookBody, false, 512, JSON_THROW_ON_ERROR);
            Log::info("Razorpay Webhook : ", [$data]);

            $api = new Api($webhookPublic, $webhookSecret);

            $metadata = $data->payload->payment->entity->notes;

            if (isset($data->event) && $data->event == 'payment.captured') {

                //checks the signature
                $expectedSignature = hash_hmac("SHA256", $webhookBody, $webhookSecret);

                $api->utility->verifyWebhookSignature($webhookBody, $expectedSignature, $webhookSecret);

                $paymentTransactionData = PaymentTransaction::where('id', $metadata->payment_transaction_id)->first();
                if ($paymentTransactionData == null) {
                    Log::error("Stripe Webhook : Payment Transaction id not found");
                }

                if ($paymentTransactionData->status == "succeed") {
                    Log::info("Stripe Webhook : Transaction Already Succeed");
                }
                $response = $this->assignPackage($metadata->payment_transaction_id, $metadata->user_id, $metadata->package_id);

                if ($response['error']) {
                    Log::error("Razorpay Webhook : ", [$response['message']]);
                }
                http_response_code(200);
            } elseif (isset($data->event) && $data->event == 'payment.failed') {
                $response = $this->failedTransaction($metadata->payment_transaction_id, $metadata->user_id);
                if ($response['error']) {
                    Log::error("Razorpay Webhook : ", [$response['message']]);
                }
                http_response_code(400);
            } elseif (isset($data->event) && $data->event == 'payment.authorized') {
//                Log::error("Razorpay Webhook : Payment Captured for ", [$data->payload->payment->entity->id]);
                http_response_code(200);
            } else {
                Log::error('Unknown Event Type', [$data->event]);
            }
        } catch (Throwable $th) {
            Log::error($th);
            Log::error('Razorpay --> Webhook Error Occurred');
            http_response_code(400);
        }
    }

    public function paystack() {
        try {
            // only a post with paystack signature header gets our attention
            if (!array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER) || (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST')) {
                echo "Signature not found";
                http_response_code(400);
                exit(0);
            }

            // Retrieve the request's body
            $input = @file_get_contents("php://input");
            $paymentConfiguration = PaymentConfiguration::select('webhook_secret_key')->where('payment_method', 'paystack')->first();
            $endpoint_secret = $paymentConfiguration['webhook_secret_key'];

            if (hash_equals($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'], hash_hmac('sha512', $input, $endpoint_secret))) {
                echo "Signature does not match";
                http_response_code(400);
                exit(0);
            }
            // parse event (which is json string) as object
            // Do something - that will not take long - with $event
            $event = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
            $metadata = $event->data->metadata;
            Log::info("Paystack Webhook event Called", [$event]);
            switch ($event->event) {
                case 'charge.success':
                    $response = $this->assignPackage($metadata->payment_transaction_id, $metadata->user_id, $metadata->package_id);
                    if ($response['error']) {
                        Log::error("Paystack Webhook : ", [$response['message']]);
                    }
                    http_response_code(200);
                    break;
                default:
                    Log::error('Paystack Webhook : Received unknown event type', [$event->event]);
            }
            http_response_code(200);
            exit();
        } catch (Throwable $e) {
            Log::error("Paystack Webhook : Error occurred", [$e->getMessage() . ' --> ' . $e->getFile() . ' At Line : ' . $e->getLine()]);
            http_response_code(400);
            exit();
        }
    }

    public function paystackSuccessCallback(){
        ResponseService::successResponse("Payment done successfully.");
    }

         public function phonePe()
    {
        try {
            Log::info("PhonePe Webhook event called");

            // Must be POST
            if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
                Log::error("Invalid request method");
                return response('Invalid request method', 400);
            }

            // Raw payload
            $content = trim(file_get_contents("php://input"));
            $jsonInput = json_decode($content, true);
            Log::info("PhonePe Webhook Raw Payload", [$jsonInput]);

            if (!$jsonInput) {
                Log::error("Invalid JSON payload");
                return response()->json(['error' => 'Invalid JSON'], 400);
            }

            // --- Step 1: Verify Authorization Header ---
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
            if (!$authHeader) {
                Log::error("Missing Authorization header");
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Fetch credentials (from DB or static values)
            $paymentConfiguration = PaymentConfiguration::where('payment_method', 'PhonePe')->first();
            $username = $paymentConfiguration->username ?? '';
            $password = $paymentConfiguration->password ?? '';


             $expectedHash = hash('sha256', $username . ':' . $password);

            if (hash_equals($expectedHash, $authHeader)) {
                Log::error("Authorization header mismatch");
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // --- Step 2: Extract Order Info ---
            $payload = $jsonInput['payload'] ?? [];
            $merchantOrderId = $payload['merchantOrderId'] ?? null;
            $orderId = $payload['orderId'] ?? null;
            $state = $payload['state'] ?? null;
            $amount = $payload['amount'] ?? null;
                        $metadata = $payload['metadata'] ?? [];

            // Try from merchantOrderId (for web)
            $transaction_id = null;
            $package_id = null;
            $user_id = null;
            
            if (!empty($merchantOrderId) && strpos($merchantOrderId, 't-') === 0) {
                $parts = explode('-', $merchantOrderId);
                $transaction_id = $parts[1] ?? null;
                $package_id = $parts[3] ?? null;
                $user_id = $metadata['user_id'] ?? null;
            }
            
            // Fallback for App SDK
            if (empty($transaction_id) || empty($package_id)) {
                $transaction_id = $metadata['payment_transaction_id'] ?? null;
                $package_id = $metadata['package_id'] ?? null;
                $user_id = $metadata['user_id'] ?? null;
            }
            
            Log::info("PhonePe Identified Meta", [
                'transaction_id' => $transaction_id,
                'package_id' => $package_id,
                'user_id' => $user_id,
            ]);

            Log::info("PhonePe Payment Event", [
                'event' => $jsonInput['event'] ?? null,
                'merchantOrderId' => $merchantOrderId,
                'orderId' => $orderId,
                'state' => $state,
                'amount' => $amount,
            ]);

            // --- Step 3: Business Logic ---
            if ($state === "COMPLETED" || $state === "SUCCESS") {
                // Parse merchantOrderId (example format: t-151-p-1)
                $parts = explode('-', $merchantOrderId);
                $transaction_id = $parts[1] ?? null;
                $package_id = $parts[3] ?? null;

                $paymentTransaction = PaymentTransaction::find($transaction_id);

                if ($paymentTransaction) {
                    $metadata = [
                        'payment_transaction_id' => $transaction_id,
                        'package_id' => $package_id,
                        'user_id' => $paymentTransaction->user_id,
                    ];

                    $response = $this->assignPackage(
                        $metadata['payment_transaction_id'],
                        $metadata['user_id'],
                        $metadata['package_id']
                    );

                    if ($response['error']) {
                        Log::error("PhonePe Webhook assignPackage error", [$response['message']]);
                    }
                }

                return response()->json(['status' => 'success'], 200);
            } else {
                Log::warning("PhonePe Payment Failed", $jsonInput);
                return response()->json(['status' => 'failed'], 400);
            }

        } catch (\Throwable $e) {
            Log::error("PhonePe Webhook Error", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
        public function flutterwave()
    {
        try {
            if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
                Log::error("Invalid request method");
                return response('Invalid request method', 400);
            }

            $content = trim(file_get_contents("php://input"));
            $payload = json_decode($content, true);
            if (!$payload || empty($payload)) {
                Log::error('Invalid webhook payload');
                return response()->json(['error' => 'Invalid payload'], 400);
            }

            if (!isset($payload['txRef']) || !isset($payload['status'])) {
                Log::error('Missing required fields in webhook payload');
                return response()->json(['error' => 'Invalid payload structure'], 400);
            }

            $transactionRef = $payload['txRef'];
            $status = $payload['status'];
            $amount = $payload['amount'];
            $currency = $payload['currency'];
            $customer = $payload['customer'];
            $transactionId = $payload['id'];

            $parts = explode('-', $transactionRef);
            $transaction_id = $parts[1];
            $package_id = $parts[3];
            $paymentTransaction = PaymentTransaction::findOrFail($transaction_id);

            if ($status === 'successful') {
                Log::info('Flutterwave Payment Successful', [
                    'transactionId' => $transactionId,
                    'transactionRef' => $transactionRef,
                    'amount' => $amount,
                    'currency' => $currency,
                    'customer' => $customer
                ]);

                $metadata = [
                    'payment_transaction_id' => $transaction_id,
                    'package_id' => $package_id,
                    'user_id' => $paymentTransaction->user_id,
                ];

                $response = $this->assignPackage($metadata['payment_transaction_id'], $metadata['user_id'], $metadata['package_id']);
                if ($response['error']) {
                    Log::error("Flutterwave Webhook : ", [$response['message']]);
                }

                return response()->json(['status' => 'success'], 200);
            } else {
                Log::warning('Flutterwave Payment Failed or Incomplete', [$payload]);
                return response()->json(['status' => 'failure'], 400);
            }
        } catch (Throwable $e) {
            Log::error("Flutterwave Webhook Error", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    public function phonePeSuccessCallback(){
        ResponseService::successResponse("Payment done successfully.");
    }

    public function paypal()
        {
            try {
                \Log::info("PayPal Webhook event called");

                // ✅ Ensure POST
                if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
                    \Log::error("Invalid request method");
                    return response('Invalid request method', 400);
                }

                // ✅ Raw payload
                $content = trim(file_get_contents("php://input"));
                $jsonInput = json_decode($content, true);
                \Log::info("PayPal Webhook Raw Payload", [$jsonInput]);

                if (!$jsonInput || empty($jsonInput)) {
                    return response()->json(['error' => 'Invalid JSON'], 400);
                }

                $eventType = $jsonInput['event_type'] ?? null;
                $resource  = $jsonInput['resource'] ?? [];

                \Log::info("PayPal Event", [
                    'event' => $eventType,
                    'orderId' => $resource['id'] ?? null,
                    'status' => $resource['status'] ?? null,
                ]);

                // --- Step 1: Handle APPROVED orders (capture payment) ---
                if ($eventType === "CHECKOUT.ORDER.APPROVED") {
                    $orderId = $resource['id'];

                    // $paypal = app(PaypalPayment::class);
                    // $capture = $paypal->capturePayment($orderId);

                    Log::info("PayPal Order checkout approved");

                    // update your DB here
                    return response()->json(['status' => 'captured'], 200);
                }

                // --- Step 2: Handle final success event ---
               if ($eventType === "PAYMENT.CAPTURE.COMPLETED") {
                    $captureId = $resource['id'];
                    $amount    = $resource['amount']['value'] ?? null;
                    $currency  = $resource['amount']['currency_code'] ?? null;
                    $customId  = $resource['custom_id'] ?? null;

                    \Log::info("PayPal Payment Completed", [
                        'captureId' => $captureId,
                        'amount'    => $amount,
                        'currency'  => $currency,
                        'custom_id' => $customId,
                    ]);

                    if ($customId) {
                        // Extract transaction_id & package_id
                        $parts = explode('-', $customId);
                        $transaction_id = $parts[1] ?? null;
                        $package_id     = $parts[3] ?? null;

                        if ($transaction_id && $package_id) {
                            $paymentTransaction = PaymentTransaction::find($transaction_id);

                            if ($paymentTransaction) {
                                $metadata = [
                                    'payment_transaction_id' => $transaction_id,
                                    'package_id'             => $package_id,
                                    'user_id'                => $paymentTransaction->user_id,
                                ];

                                $response = $this->assignPackage(
                                    $metadata['payment_transaction_id'],
                                    $metadata['user_id'],
                                    $metadata['package_id']
                                );

                                if ($response['error']) {
                                    \Log::error("PayPal Webhook assignPackage error", [$response['message']]);
                                }
                            } else {
                                \Log::error("PayPal Webhook: PaymentTransaction not found", [
                                    'transaction_id' => $transaction_id
                                ]);
                            }
                        }
                    }

                    return response()->json(['status' => 'success'], 200);
                }

                // --- Step 3: Handle failed/refunded ---
                if (in_array($eventType, ["PAYMENT.CAPTURE.DENIED", "PAYMENT.CAPTURE.REFUNDED"])) {
                    \Log::warning("PayPal Payment Issue", $jsonInput);
                    return response()->json(['status' => 'failed'], 200);
                }

                return response()->json(['status' => 'ignored'], 200);

            } catch (\Throwable $e) {
                \Log::error("PayPal Webhook Error", [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        }

      private function handlePaypalCapture($orderId)
        {
            $payment = PaymentConfiguration::where([
                'payment_method' =>'paypal',
                'status' => 1
            ])->first();

            if (!$payment) {
                throw new \Exception("PayPal payment configuration not found.");
            }

            $paypal = new PayPalPayment(
                $payment->api_key,
                $payment->secret_key,
                $payment->currency_code,
                $payment->payment_mode
            );

            return $paypal->capturePayment($orderId);
        }

        public function paypalPaymentSuccess(Request $request)
        {
            try {
                \Log::info("PayPal Success Raw Payload", [$request->all()]);

                $orderId = $request->query('token');

                if (!$orderId) {
                    return view('payment.paypal', [
                        'trxref'    => null,
                        'reference' => null
                    ]);
                }

                $paymentResult = $this->handlePaypalCapture($orderId);

                \Log::info("PayPal Success Redirect Capture", (array) $paymentResult);

                return view('payment.paypal', [
                    'trxref'    => $orderId,
                    'reference' => $paymentResult['id'] ?? null
                ]);

            } catch (\Throwable $e) {
                \Log::error("PayPal Success Handler Error", [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);

                return view('payment.paypal', [
                    'trxref'    => null,
                    'reference' => null
                ]);
            }
        }

        public function paypalSuccessCallback(Request $request)
        {
            try {
                \Log::info("PayPal Success callback for app");
                  \Log::info("PayPal Success Raw Payload for app", [$request->all()]);
                 $orderId = $request->query('token');
                if (!$orderId) {
                    return ResponseService::errorResponse("Missing PayPal order ID.");
                }

                $paymentResult = $this->handlePaypalCapture($orderId);
                \Log::info("PayPal Success Redirect Capture For App", (array) $paymentResult);
                return ResponseService::successResponse("Payment done successfully.");

            } catch (\Throwable $e) {
                \Log::error("PayPal Success Callback Error", [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);

                return ResponseService::errorResponse("Something went wrong during PayPal payment.");
            }
        }

        public function paypalCancelCallback()
        {
              return ResponseService::successResponse("Payment Cancelled successfully.");
        }
        public function paypalCancelCallbackWeb()
        {
            try {
                $orderId = $request->query('token');
                return view('payment.paypal', [
                    'trxref'    => $orderId ?? null,
                    'reference' => null
                ]);

            } catch (\Throwable $e) {
                \Log::error("PayPal Success Handler Error", [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);

                return view('payment.paypalcancle', [
                    'trxref'    => null,
                    'reference' => null
                ]);
            }
        }
        /**
         * Success Business Login
         * @param $payment_transaction_id
         * @param $user_id
         * @param $package_id
         * @return array
         */
       private function assignPackage($payment_transaction_id, $user_id, $package_id) {
            try {
        $paymentTransactionData = PaymentTransaction::where('id', $payment_transaction_id)->first();
        if ($paymentTransactionData == null) {
            Log::error("Payment Transaction id not found");
            return [
                'error'   => true,
                'message' => 'Payment Transaction id not found'
            ];
        }

        if ($paymentTransactionData->status == "succeed") {
            Log::info("Transaction Already Succeed");
            return [
                'error'   => true,
                'message' => 'Transaction Already Succeed'
            ];
        }

        DB::beginTransaction();
        $paymentTransactionData->update(['payment_status' => "succeed"]);

        $package = Package::find($package_id);

        if (!empty($package)) {
            // create purchased package record
            $userPackage = UserPurchasedPackage::create([
                'package_id'  => $package_id,
                'user_id'     => $user_id,
                'start_date'  => Carbon::now(),
                'end_date'    => $package->duration == "unlimited" ? null : Carbon::now()->addDays($package->duration),
                'total_limit' => $package->item_limit == "unlimited" ? null : $package->item_limit,
            ]);

            // 🔹 Update user's items expiry if they belong to this package
            // $query = Item::where('user_id', $user_id)
            //             ->where('package_id', $package_id);

            // if ($package->duration == "unlimited") {
            //     $query->update(['expiry_date' => null]);
            // } else {
            //     $query->update([
            //         'expiry_date' => Carbon::now()->addDays($package->duration)
            //     ]);
            // }
        }

        $title = "Package Purchased";
        $body = 'Amount :- ' . $paymentTransactionData->amount;
        $userTokens = UserFcmToken::where('user_id', $user_id)->pluck('fcm_token')->toArray();
        if (!empty($userTokens)) {
            NotificationService::sendFcmNotification($userTokens, $title, $body, 'payment');
        }
        DB::commit();

        return [
            'error'   => false,
            'message' => 'Transaction Verified Successfully'
        ];

        } catch (Throwable $th) {
        DB::rollBack();
        Log::error($th->getMessage() . "WebhookController -> assignPackage");
        return [
            'error'   => true,
            'message' => 'Error Occurred'
        ];
        }
    }

    public function flutterWaveSuccessCallback(){
        ResponseService::successResponse("Payment done successfully.");
    }
    /**
     * Failed Business Logic
     * @param $payment_transaction_id
     * @param $user_id
     * @return array
     */
    private function failedTransaction($payment_transaction_id, $user_id) {
        try {
            $paymentTransactionData = PaymentTransaction::find($payment_transaction_id);
            if (!$paymentTransactionData) {
                return [
                    'error'   => true,
                    'message' => 'Payment Transaction id not found'
                ];
            }

            $paymentTransactionData->update(['payment_status' => "failed"]);

            $body = 'Amount :- ' . $paymentTransactionData->amount;
            $userTokens = UserFcmToken::where('user_id', $user_id)->pluck('fcm_token')->toArray();
            NotificationService::sendFcmNotification($userTokens, 'Package Payment Failed', $body, 'payment');
            return [
                'error'   => false,
                'message' => 'Transaction Verified Successfully'
            ];
        } catch (Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage() . "WebhookController -> failedTransaction");
            return [
                'error'   => true,
                'message' => 'Error Occurred'
            ];
        }
    }
}

