<?php

namespace App\Services\Payment;

use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripePayment implements PaymentInterface {
    private StripeClient $stripe;
    private string $currencyCode;

    /**
     * StripePayment constructor.
     * @param $secretKey
     * @param $currencyCode
     */
    public function __construct($secretKey, $currencyCode) {
        // Call Stripe Class and Create Payment Intent
        $this->stripe = new StripeClient($secretKey);
        $this->currencyCode = $currencyCode;
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return PaymentIntent
     * @throws ApiErrorException
     */
    public function createPaymentIntent($amount, $customMetaData) {
        try {
            $amount = $this->minimumAmountValidation($this->currencyCode, $amount);
            $zeroDecimalCurrencies = [
                'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
                'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
            ];

            // if (!in_array($this->currencyCode, $zeroDecimalCurrencies)) {
            //     $amount *= 100;
            // }
            return $this->stripe->paymentIntents->create(
                [
                    'amount'   => $amount,
                    'currency' => $this->currencyCode,
                    'metadata' => $customMetaData,
                    //                    'description' => 'Fees Payment',
                    //                    'shipping' => [
                    //                        'name' => 'Jenny Rosen',
                    //                        'address' => [
                    //                            'line1' => '510 Townsend St',
                    //                            'postal_code' => '98140',
                    //                            'city' => 'San Francisco',
                    //                            'state' => 'CA',
                    //                            'country' => 'US',
                    //                        ],
                    //                    ],
                ]
            );

        } catch (ApiErrorException $e) {
            throw $e;
        }
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return array
     * @throws ApiErrorException
     */
    public function createAndFormatPaymentIntent($amount, $customMetaData): array {
        $paymentIntent = $this->createPaymentIntent($amount, $customMetaData);
        return $this->format($paymentIntent);
    }

    /**
     * @param $paymentId
     * @return array
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent($paymentId): array {
        try {
            return $this->format($this->stripe->paymentIntents->retrieve($paymentId));
        } catch (ApiErrorException $e) {
            throw $e;
        }
    }

    /**
     * @param $paymentIntent
     * @return array
     */
    public function format($paymentIntent) {
        return $this->formatPaymentIntent($paymentIntent->id, $paymentIntent->amount, $paymentIntent->currency, $paymentIntent->status, $paymentIntent->metadata, $paymentIntent);
    }

    /**
     * @param $id
     * @param $amount
     * @param $currency
     * @param $status
     * @param $metadata
     * @param $paymentIntent
     * @return array
     */
    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array {
        return [
            'id'                       => $paymentIntent->id,
            'amount'                   => $paymentIntent->amount,
            'currency'                 => $paymentIntent->currency,
            'metadata'                 => $paymentIntent->metadata,
            'status'                   => match ($paymentIntent->status) {
                "canceled" => "failed",
                "succeeded" => "succeed",
                "processing", "requires_action", "requires_capture", "requires_confirmation", "requires_payment_method" => "pending",
            },
            'payment_gateway_response' => $paymentIntent
        ];
    }

    /**
     * @param $currency
     * @param $amount
     * @return float|int
     */
    public function minimumAmountValidation($currency, $amount) {
        $minimumAmountMap = [
            'USD' => 0.50, 'EUR' => 0.50, 'INR' => 0.50, 'NZD' => 0.50, 'SGD' => 0.50,
            'BRL' => 0.50, 'CAD' => 0.50, 'AUD' => 0.50, 'CHF' => 0.50,
            'AED' => 2.00, 'PLN' => 2.00, 'RON' => 2.00,
            'BGN' => 1.00, 'CZK' => 15.00, 'DKK' => 2.50,
            'GBP' => 0.30, 'HKD' => 4.00, 'HUF' => 175.00,
            'JPY' => 50, 'MXN' => 10, 'THB' => 10, 'MYR' => 2,
            'NOK' => 3.00, 'SEK' => 3.00, 'XAF' => 100
        ];

        $zeroDecimalCurrencies = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
            'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
        ];

        // Convert minimum amount to smallest unit (paise)
        $minimumAmount = $minimumAmountMap[$currency] ?? 0.50;

        if (!in_array($currency, $zeroDecimalCurrencies)) {
            $minimumAmount *= 100;  // Convert to smallest unit
            $amount *= 100;         // Convert amount to smallest unit
        }

        return max($amount, $minimumAmount);
    }
}
