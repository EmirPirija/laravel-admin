<?php

namespace App\Services\Payment;

use App\Services\ResponseService;
use KingFlamez\Rave\Rave as Flutterwave;
use Exception;
use Illuminate\Support\Facades\Auth;



class FlutterwavePayment implements PaymentInterface
{
    private string $currencyCode;
    private Flutterwave $flutterwave;
    private string $encryptionKey;
    protected $baseUrl;
    private string $secretKey;

    public function __construct($secret_key, $public_key, $encryption_key, $currencyCode)
    {
        $this->currencyCode = $currencyCode;
        $this->encryptionKey = $encryption_key;
        $this->baseUrl = 'https://api.flutterwave.com/v3';
        $this->secretKey = $secret_key;
        // Initialize Flutterwave SDK with API keys
        $this->flutterwave = new Flutterwave([
            'publicKey' => $public_key,
            'secretKey' => $secret_key,
            'encryptionKey' => $encryption_key,
        ]);
    }

    public function createPaymentIntent($amount, $customMetaData)
    {
        try {
            if (empty($customMetaData['email'])) {
                throw new Exception("Email cannot be empty");
            }
            $redirectUrl = ($customMetaData['platform_type'] == 'app')
            ? route('flutterwave.success')
            : route('flutterwave.success.web');

            $finalAmount =$amount;
            // $transactionRef = uniqid('flw_');
            $transactionRef = 't' .'-'. $customMetaData['payment_transaction_id'] .'-'. 'p' .'-'. $customMetaData['package_id'];
            $data = [
                'tx_ref' => $transactionRef,
                'amount' => $finalAmount,
                'currency' => $this->currencyCode,
                'redirect_url' => $redirectUrl,
                'payment_options' => 'card,banktransfer', // You can add more payment options
                'customer' => [
                    'email' => $customMetaData['email'],
                    'phonenumber' => $customMetaData['phone'] ?? Auth::user()->mobile,
                    'name' => $customMetaData['name'] ?? Auth::user()->name,
                ],
                'meta' => [
                    'package_id' => $customMetaData['package_id'],
                    'user_id' => $customMetaData['user_id'],
                ]
            ];
            $data = json_encode($data, JSON_UNESCAPED_SLASHES);
            $url = 'https://api.flutterwave.com/v3/payments';

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->secretKey
            ]);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                return ['error' => curl_error($ch)];
            }
            curl_close($ch);
            $payment = json_decode($response,true);
           return $this->formatPaymentIntent($transactionRef, $finalAmount,$this->currencyCode,'pending',$customMetaData,$payment);
        } catch (Exception $e) {
            return ResponseService::errorResponse("Payment failed: " . $e->getMessage());
        }
    }



    public function createAndFormatPaymentIntent($amount, $customMetaData): array
    {
        return $this->createPaymentIntent($amount, $customMetaData);
    }

    public function retrievePaymentIntent($transactionId): array
    {
        try {
            $response = $this->flutterwave->verifyTransaction($transactionId);
            if ($response['status'] === 'success') {
                return $this->formatPaymentIntent(
                    $response['data']['tx_ref'],
                    $response['data']['amount'],
                    $response['data']['currency'],
                    $response['data']['status'],
                    [],
                    $response
                );
            }
            throw new Exception("Error fetching payment status: " . $response['message']);
        } catch (Exception $e) {
            throw new Exception("Error verifying transaction: " . $e->getMessage());
        }
    }

    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array
    {
        return [
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'status' => match ($status) {
                'successful' => 'succeeded',
                'pending' => 'pending',
                'failed' => 'failed',
                default => 'unknown'
            },
            'payment_gateway_response' => $paymentIntent
        ];
    }

    public function minimumAmountValidation($currency, $amount)
    {
        $minimumAmount = match ($currency) {
            'NGN' => 50, // 50 Naira
            default => 1.00
        };

        return ($amount >= $minimumAmount) ? $amount : $minimumAmount;
    }
}
