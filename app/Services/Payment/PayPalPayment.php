<?php

namespace App\Services\Payment;

use Exception;
use GuzzleHttp\Client;

class PayPalPayment implements PaymentInterface
{
    private string $clientId;
    private string $secretKey;
    private string $currencyCode;
    private string $paymentmode;
    private Client $http;

    public function __construct($clientId, $secretKey, $currencyCode, $paymentmode)
    {
        $this->clientId     = $clientId;
        $this->secretKey    = $secretKey;
        $this->currencyCode = 'USD';
        $this->paymentmode    = $paymentmode;

        $this->http = new Client([
            'base_uri' => ($paymentmode == "UAT") ? 'https://api.sandbox.paypal.com' : 'https://api-m.paypal.com',
            'timeout'  => 30,
        ]);
    }

    private function generateAccessToken(): string
    {
        $response = $this->http->post('/v1/oauth2/token', [
            'auth' => [$this->clientId, $this->secretKey],
            'form_params' => ['grant_type' => 'client_credentials'],
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US'
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true);
        return $data['access_token'] ?? throw new Exception("Unable to generate PayPal token");
    }

    /**
     * Create a PayPal Order (Payment Intent equivalent)
     */
    public function createPaymentIntent($amount, $customMetaData)
    {
        $amount = $this->minimumAmountValidation($this->currencyCode, $amount);
        $accessToken = $this->generateAccessToken();
        if($customMetaData['platform_type'] == 'app') {
                $callbackUrl = route('paypal.success') ;
            }else{
                $callbackUrl = route('paypal.success.web');
            }
              $metaData = 't' . '-' . $customMetaData['payment_transaction_id'] . '-' . 'p' . '-' . $customMetaData['package_id'];
        $response = $this->http->post('/v2/checkout/orders', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json'
            ],
            'json' => [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => $this->currencyCode,
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'custom_id'   => $metaData,
                    'description' => $customMetaData['description'] ?? null,
                ]],
                'application_context' => [
                    'return_url' => $callbackUrl,
                    'cancel_url' => $callbackUrl,
                ]
            ]

        ]);

        $data = json_decode((string)$response->getBody(), true);
        return $data;
    }


    /**
     * ✅ Matches PaymentInterface (like Stripe)
     */
    public function createAndFormatPaymentIntent($amount, $customMetaData): array
    {
        $order = $this->createPaymentIntent($amount, $customMetaData);

        // Extract approve link from PayPal response
        $approveLink = null;
        if (isset($order['links']) && is_array($order['links'])) {
            foreach ($order['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveLink = $link['href'];
                    break;
                }
            }
        }

        $formatted = $this->formatPaymentIntent(
            $order['id'],
            $amount,
            $this->currencyCode,
            $order['status'] ?? 'CREATED',
            $customMetaData,
            $order
        );

        // Add approval link for frontend
        $formatted['approval_url'] = $approveLink;

        return $formatted;
    }


    /**
     * ✅ Retrieve order details (similar to Stripe’s retrievePaymentIntent)
     */
    public function retrievePaymentIntent($paymentId): array
    {
        $accessToken = $this->generateAccessToken();

        $response = $this->http->get("/v2/checkout/orders/{$paymentId}", [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json'
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true);

        return $this->formatPaymentIntent(
            $data['id'],
            $data['purchase_units'][0]['amount']['value'] ?? 0,
            $data['purchase_units'][0]['amount']['currency_code'] ?? $this->currencyCode,
            $data['status'],
            [],
            $data
        );
    }

    public function capturePayment($orderId): array
    {
        $accessToken = $this->generateAccessToken();

        $response = $this->http->post("/v2/checkout/orders/{$orderId}/capture", [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json'
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true);

        return $this->formatPaymentIntent(
            $data['id'] ?? $orderId,
            $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0,
            $data['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? $this->currencyCode,
            $data['status'] ?? 'FAILED',
            [],
            $data
        );
    }

    public function refund($captureId, $amount, $currency = null): array
    {
        $accessToken = $this->generateAccessToken();
        $currency = $currency ?? $this->currencyCode;

        $response = $this->http->post("/v2/payments/captures/{$captureId}/refund", [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json'
            ],
            'json' => [
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => $currency
                ]
            ]
        ]);

        return json_decode((string)$response->getBody(), true);
    }

    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array
    {
        return [
            'id'       => $id,
            'amount'   => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'status'   => match (strtolower($status)) {
                "completed" => "succeed",
                "approved"  => "pending",
                "created"   => "pending",
                default     => "failed",
            },
            'payment_gateway_response' => $paymentIntent
        ];
    }

    public function minimumAmountValidation($currency, $amount)
    {
        $minimumAmount = 0.50;
        return max($amount, $minimumAmount);
    }
}
