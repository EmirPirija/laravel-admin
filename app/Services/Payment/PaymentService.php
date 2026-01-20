<?php

namespace App\Services\Payment;

use App\Models\PaymentConfiguration;
use InvalidArgumentException;

class PaymentService {
    /**
     * @param string $paymentGateway - Stripe
     * @return StripePayment
     */
    public static function create(string $paymentGateway) {
        $paymentGateway = strtolower($paymentGateway);
        $payment = PaymentConfiguration::where(['payment_method' => $paymentGateway, 'status' => 1])->first();

        if (!$payment) {
            throw new InvalidArgumentException('Invalid Payment Gateway.');
        }
        return match ($paymentGateway) {
            'stripe' => new StripePayment($payment->secret_key, $payment->currency_code),
            'paystack' => new PaystackPayment($payment->currency_code),
            'razorpay' => new RazorpayPayment($payment->secret_key, $payment->api_key, $payment->currency_code),
            'phonepe' => new PhonePePayment($payment->secret_key , $payment->api_key,$payment->additional_data_1,$payment->additional_data_2,$payment->payment_mode),
            'flutterwave' => new FlutterWavePayment($payment->secret_key, $payment->api_key, $payment->webhook_secret_key, $payment->currency_code),
            'paypal' => new PayPalPayment($payment->api_key, $payment->secret_key, $payment->currency_code,$payment->payment_mode),
            'google,apple' => null,
            // any other payment processor implementations
            default => throw new InvalidArgumentException('Invalid Payment Gateway.'),
        };
    }

    /***
     * @param string $paymentGateway
     * @param $paymentIntentData
     * @return array
     * Stripe Payment Intent : https://stripe.com/docs/api/payment_intents/object
     */
//    public static function formatPaymentIntent(string $paymentGateway, $paymentIntentData) {
//        $paymentGateway = strtolower($paymentGateway);
//        return match ($paymentGateway) {
//            'stripe' => [
//                'id'                       => $paymentIntentData->id,
//                'amount'                   => $paymentIntentData->amount,
//                'currency'                 => $paymentIntentData->currency,
//                'metadata'                 => $paymentIntentData->metadata,
//                'status'                   => match ($paymentIntentData->status) {
//                    "canceled" => "failed",
//                    "succeeded" => "succeed",
//                    "processing", "requires_action", "requires_capture", "requires_confirmation", "requires_payment_method" => "pending",
//                },
//                'payment_gateway_response' => $paymentIntentData
//            ],
//
//            'paystack' => [
//                'id'                       => $paymentIntentData['data']['reference'],
//                'amount'                   => $paymentIntentData->amount,
//                'currency'                 => $paymentIntentData->currency,
//                'metadata'                 => $paymentIntentData->metadata,
//                'status'                   => match ($paymentIntentData['data']['status']) {
//                    "abandoned" => "failed",
//                    "succeed" => "succeed",
//                    default => $paymentIntentData['data']['status'] ?? true
//                },
//                'payment_gateway_response' => $paymentIntentData
//            ],
//            // any other payment processor implementations
//            default => $paymentIntentData,
//        };
//    }
}
