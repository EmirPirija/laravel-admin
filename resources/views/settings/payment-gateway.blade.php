@extends('layouts.main')

@section('title')
    {{ __('Payment Gateways Settings') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first"></div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <form class="create-form-without-reset" action="{{ route('settings.payment-gateway.store') }}" method="post"
            enctype="multipart/form-data">
            <div class="row d-flex mb-3">

                {{-- Stripe Payment Gateway START --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Stripe Setting') }}</h6>
                            </div>

                            <div class="form-group row mt-3">
                                <label for="stripe_currency_code"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Stripe Currency Symbol') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select name="gateway[Stripe][currency_code]" id="stripe_currency_code"
                                        class="select2 form-select form-control">
                                        <option value="USD">USD</option>
                                        <option value="AED">AED</option>
                                        <option value="AFN">AFN</option>
                                        <option value="ALL">ALL</option>
                                        <option value="AMD">AMD</option>
                                        <option value="ANG">ANG</option>
                                        <option value="AOA">AOA</option>
                                        <option value="ARS">ARS</option>
                                        <option value="AUD">AUD</option>
                                        <option value="AWG">AWG</option>
                                        <option value="AZN">AZN</option>
                                        <option value="BAM">BAM</option>
                                        <option value="BBD">BBD</option>
                                        <option value="BDT">BDT</option>
                                        <option value="BGN">BGN</option>
                                        <option value="BMD">BMD</option>
                                        <option value="BND">BND</option>
                                        <option value="BOB">BOB</option>
                                        <option value="BRL">BRL</option>
                                        <option value="BSD">BSD</option>
                                        <option value="BWP">BWP</option>
                                        <option value="BYN">BYN</option>
                                        <option value="BZD">BZD</option>
                                        <option value="CAD">CAD</option>
                                        <option value="CDF">CDF</option>
                                        <option value="CHF">CHF</option>
                                        <option value="CNY">CNY</option>
                                        <option value="COP">COP</option>
                                        <option value="CRC">CRC</option>
                                        <option value="CVE">CVE</option>
                                        <option value="CZK">CZK</option>
                                        <option value="DKK">DKK</option>
                                        <option value="DOP">DOP</option>
                                        <option value="DZD">DZD</option>
                                        <option value="EGP">EGP</option>
                                        <option value="ETB">ETB</option>
                                        <option value="EUR">EUR</option>
                                        <option value="FJD">FJD</option>
                                        <option value="FKP">FKP</option>
                                        <option value="GBP">GBP</option>
                                        <option value="GEL">GEL</option>
                                        <option value="GIP">GIP</option>
                                        <option value="GMD">GMD</option>
                                        <option value="GTQ">GTQ</option>
                                        <option value="GYD">GYD</option>
                                        <option value="HKD">HKD</option>
                                        <option value="HNL">HNL</option>
                                        <option value="HTG">HTG</option>
                                        <option value="HUF">HUF</option>
                                        <option value="IDR">IDR</option>
                                        <option value="ILS">ILS</option>
                                        <option value="INR">INR</option>
                                        <option value="ISK">ISK</option>
                                        <option value="JMD">JMD</option>
                                        <option value="KES">KES</option>
                                        <option value="KGS">KGS</option>
                                        <option value="KHR">KHR</option>
                                        <option value="KYD">KYD</option>
                                        <option value="KZT">KZT</option>
                                        <option value="LAK">LAK</option>
                                        <option value="LBP">LBP</option>
                                        <option value="LKR">LKR</option>
                                        <option value="LRD">LRD</option>
                                        <option value="LSL">LSL</option>
                                        <option value="MAD">MAD</option>
                                        <option value="MDL">MDL</option>
                                        <option value="MKD">MKD</option>
                                        <option value="MMK">MMK</option>
                                        <option value="MNT">MNT</option>
                                        <option value="MOP">MOP</option>
                                        <option value="MUR">MUR</option>
                                        <option value="MVR">MVR</option>
                                        <option value="MWK">MWK</option>
                                        <option value="MXN">MXN</option>
                                        <option value="MYR">MYR</option>
                                        <option value="MZN">MZN</option>
                                        <option value="NAD">NAD</option>
                                        <option value="NGN">NGN</option>
                                        <option value="NIO">NIO</option>
                                        <option value="NOK">NOK</option>
                                        <option value="NPR">NPR</option>
                                        <option value="NZD">NZD</option>
                                        <option value="PAB">PAB</option>
                                        <option value="PEN">PEN</option>
                                        <option value="PGK">PGK</option>
                                        <option value="PHP">PHP</option>
                                        <option value="PKR">PKR</option>
                                        <option value="PLN">PLN</option>
                                        <option value="QAR">QAR</option>
                                        <option value="RON">RON</option>
                                        <option value="RSD">RSD</option>
                                        <option value="RUB">RUB</option>
                                        <option value="SAR">SAR</option>
                                        <option value="SBD">SBD</option>
                                        <option value="SCR">SCR</option>
                                        <option value="SEK">SEK</option>
                                        <option value="SGD">SGD</option>
                                        <option value="SHP">SHP</option>
                                        <option value="SLE">SLE</option>
                                        <option value="SOS">SOS</option>
                                        <option value="SRD">SRD</option>
                                        <option value="STD">STD</option>
                                        <option value="SZL">SZL</option>
                                        <option value="THB">THB</option>
                                        <option value="TJS">TJS</option>
                                        <option value="TOP">TOP</option>
                                        <option value="TRY">TRY</option>
                                        <option value="TTD">TTD</option>
                                        <option value="TWD">TWD</option>
                                        <option value="TZS">TZS</option>
                                        <option value="UAH">UAH</option>
                                        <option value="UYU">UYU</option>
                                        <option value="UZS">UZS</option>
                                        <option value="WST">WST</option>
                                        <option value="XAF">XAF</option>
                                        <option value="XCD">XCD</option>
                                        <option value="YER">YER</option>
                                        <option value="ZAR">ZAR</option>
                                        <option value="ZMW">ZMW</option>
                                    </select>
                                </div>

                                <label for="stripe_secret_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Stripe Secret key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="stripe_secret_key" name="gateway[Stripe][secret_key]" type="text"
                                        class="form-control" placeholder="{{ __('Stripe Secret key') }}"
                                        value="{{ $paymentGateway['Stripe']['secret_key'] ?? '' }}">
                                </div>

                                <label for="stripe_publishable_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Stripe Publishable key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="stripe_publishable_key" name="gateway[Stripe][api_key]" type="text"
                                        class="form-control" placeholder="{{ __('Stripe Publishable key') }}"
                                        value="{{ $paymentGateway['Stripe']['api_key'] ?? '' }}">
                                </div>

                                <label for="stripe_webhook_secret"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Stripe Webhook Secret') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="stripe_webhook_secret" name="gateway[Stripe][webhook_secret_key]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Stripe Webhook Secret') }}"
                                        value="{{ $paymentGateway['Stripe']['webhook_secret_key'] ?? '' }}">
                                </div>

                                <label for="stripe_webhook_url"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Stripe Webhook URL') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="stripe_webhook_url" name="gateway[Stripe][webhook_url]" type="text"
                                        class="form-control" placeholder="{{ __('Stripe Webhook URL') }}"
                                        value="{{ url('/webhook/stripe') }}" disabled>
                                </div>

                                <label class="col-sm-12 form-check-label  mt-2"
                                    id='lbl_stripe'>{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12  mt-2">
                                    <div class="form-check form-switch ">
                                        <input type="hidden" name="gateway[Stripe][status]" id="stripe_gateway"
                                            value="{{ $paymentGateway['Stripe']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['Stripe']['status']) && $paymentGateway['Stripe']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_stripe_gateway" aria-label="switch_stripe_gateway">
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                {{-- Stripe Payment Gateway END --}}

                {{-- Razorpay Payment Gateway START --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Razorpay Setting') }}</h6>
                            </div>

                            <div class="form-group row mt-3">
                                <label for="razorpay_currency_code"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Razorpay Currency Symbol') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select name="gateway[Razorpay][currency_code]" id="razorpay_currency_code"
                                        class="select2 form-select form-control">
                                        <option value="AED">AED</option>
                                        <option value="ALL">ALL</option>
                                        <option value="AMD">AMD</option>
                                        <option value="ARS">ARS</option>
                                        <option value="AUD">AUD</option>
                                        <option value="AWG">AWG</option>
                                        <option value="AZN">AZN</option>
                                        <option value="BAM">BAM</option>
                                        <option value="BBD">BBD</option>
                                        <option value="BDT">BDT</option>
                                        <option value="BGN">BGN</option>
                                        <option value="BHD">BHD</option>
                                        <option value="BIF">BIF</option>
                                        <option value="BMD">BMD</option>
                                        <option value="BND">BND</option>
                                        <option value="BOB">BOB</option>
                                        <option value="BRL">BRL</option>
                                        <option value="BSD">BSD</option>
                                        <option value="BTN">BTN</option>
                                        <option value="BWP">BWP</option>
                                        <option value="BZD">BZD</option>
                                        <option value="CAD">CAD</option>
                                        <option value="CHF">CHF</option>
                                        <option value="CLP">CLP</option>
                                        <option value="CNY">CNY</option>
                                        <option value="COP">COP</option>
                                        <option value="CRC">CRC</option>
                                        <option value="CUP">CUP</option>
                                        <option value="CVE">CVE</option>
                                        <option value="CZK">CZK</option>
                                        <option value="DJF">DJF</option>
                                        <option value="DKK">DKK</option>
                                        <option value="DOP">DOP</option>
                                        <option value="DZD">DZD</option>
                                        <option value="EGP">EGP</option>
                                        <option value="ETB">ETB</option>
                                        <option value="EUR">EUR</option>
                                        <option value="FJD">FJD</option>
                                        <option value="GBP">GBP</option>
                                        <option value="GHS">GHS</option>
                                        <option value="GIP">GIP</option>
                                        <option value="GMD">GMD</option>
                                        <option value="GNF">GNF</option>
                                        <option value="GTQ">GTQ</option>
                                        <option value="GYD">GYD</option>
                                        <option value="HKD">HKD</option>
                                        <option value="HNL">HNL</option>
                                        <option value="HRK">HRK</option>
                                        <option value="HTG">HTG</option>
                                        <option value="HUF">HUF</option>
                                        <option value="IDR">IDR</option>
                                        <option value="ILS">ILS</option>
                                        <option value="INR">INR</option>
                                        <option value="IQD">IQD</option>
                                        <option value="ISK">ISK</option>
                                        <option value="JMD">JMD</option>
                                        <option value="JOD">JOD</option>
                                        <option value="JPY">JPY</option>
                                        <option value="KES">KES</option>
                                        <option value="KGS">KGS</option>
                                        <option value="KHR">KHR</option>
                                        <option value="KMF">KMF</option>
                                        <option value="KRW">KRW</option>
                                        <option value="KWD">KWD</option>
                                        <option value="KYD">KYD</option>
                                        <option value="KZT">KZT</option>
                                        <option value="LAK">LAK</option>
                                        <option value="LKR">LKR</option>
                                        <option value="LRD">LRD</option>
                                        <option value="LSL">LSL</option>
                                        <option value="MAD">MAD</option>
                                        <option value="MDL">MDL</option>
                                        <option value="MGA">MGA</option>
                                        <option value="MKD">MKD</option>
                                        <option value="MMK">MMK</option>
                                        <option value="MNT">MNT</option>
                                        <option value="MOP">MOP</option>
                                        <option value="MUR">MUR</option>
                                        <option value="MVR">MVR</option>
                                        <option value="MWK">MWK</option>
                                        <option value="MXN">MXN</option>
                                        <option value="MYR">MYR</option>
                                        <option value="MZN">MZN</option>
                                        <option value="NAD">NAD</option>
                                        <option value="NGN">NGN</option>
                                        <option value="NIO">NIO</option>
                                        <option value="NOK">NOK</option>
                                        <option value="NPR">NPR</option>
                                        <option value="NZD">NZD</option>
                                        <option value="OMR">OMR</option>
                                        <option value="PEN">PEN</option>
                                        <option value="PGK">PGK</option>
                                        <option value="PHP">PHP</option>
                                        <option value="PKR">PKR</option>
                                        <option value="PLN">PLN</option>
                                        <option value="PYG">PYG</option>
                                        <option value="QAR">QAR</option>
                                        <option value="RON">RON</option>
                                        <option value="RSD">RSD</option>
                                        <option value="RUB">RUB</option>
                                        <option value="RWF">RWF</option>
                                        <option value="SAR">SAR</option>
                                        <option value="SCR">SCR</option>
                                        <option value="SEK">SEK</option>
                                        <option value="SGD">SGD</option>
                                        <option value="SLL">SLL</option>
                                        <option value="SOS">SOS</option>
                                        <option value="SSP">SSP</option>
                                        <option value="SVC">SVC</option>
                                        <option value="SZL">SZL</option>
                                        <option value="THB">THB</option>
                                        <option value="TND">TND</option>
                                        <option value="TRY">TRY</option>
                                        <option value="TTD">TTD</option>
                                        <option value="TWD">TWD</option>
                                        <option value="TZS">TZS</option>
                                        <option value="UAH">UAH</option>
                                        <option value="UGX">UGX</option>
                                        <option value="USD">USD</option>
                                        <option value="UYU">UYU</option>
                                        <option value="UZS">UZS</option>
                                        <option value="VND">VND</option>
                                        <option value="VUV">VUV</option>
                                        <option value="XAF">XAF</option>
                                        <option value="XCD">XCD</option>
                                        <option value="XOF">XOF</option>
                                        <option value="XPF">XPF</option>
                                        <option value="YER">YER</option>
                                        <option value="ZAR">ZAR</option>
                                        <option value="ZMW">ZMW</option>

                                    </select>
                                </div>

                                <label for="razorpay_secret_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Razorpay Secret key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="razorpay_secret_key" name="gateway[Razorpay][secret_key]" type="text"
                                        class="form-control" placeholder="{{ __('Razorpay Secret key') }}"
                                        value="{{ $paymentGateway['Razorpay']['secret_key'] ?? '' }}">
                                </div>

                                <label for="razorpay_public_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Razorpay Public key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="razorpay_public_key" name="gateway[Razorpay][api_key]" type="text"
                                        class="form-control" placeholder="{{ __('Razorpay Publishable key') }}"
                                        value="{{ $paymentGateway['Razorpay']['api_key'] ?? '' }}">
                                </div>

                                <label for="razorpay_webhook_secret"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Razorpay Webhook Secret') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="razorpay_webhook_secret" name="gateway[Razorpay][webhook_secret_key]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Razorpay Webhook Secret') }}"
                                        value="{{ $paymentGateway['Razorpay']['webhook_secret_key'] ?? '' }}">
                                </div>

                                <label for="razorpay_webhook_url"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Razorpay Webhook URL') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="razorpay_webhook_url" name="gateway[Razorpay][webhook_url]" type="text"
                                        class="form-control" placeholder="{{ __('Razorpay Webhook URL') }}"
                                        value="{{ url('/webhook/razorpay') }}" disabled>
                                </div>

                                <label class="col-sm-12 form-check-label  mt-2"
                                    id='lbl_stripe'>{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12  mt-2">
                                    <div class="form-check form-switch ">
                                        <input type="hidden" name="gateway[Razorpay][status]" id="razorpay_gateway"
                                            value="{{ $paymentGateway['Razorpay']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['Razorpay']['status']) && $paymentGateway['Razorpay']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_razorpay_gateway" aria-label="switch_razorpay_gateway">
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                {{-- Razorpay Payment Gateway END --}}

                {{-- Paystack Payment Gateway START --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Paystack Setting') }}</h6>
                            </div>
                            <div class="form-group row mt-3">
                                <label for="paystack_currency_code"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Paystack Currency Symbol') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select name="gateway[Paystack][currency_code]" id="paystack_currency_code"
                                        class="select2 form-select form-control">
                                        <option value="USD">USD</option>
                                        <option value="GHS">GHS</option>
                                        <option value="KES">KES</option>
                                        <option value="NGN">NGN</option>
                                        <option value="ZAR">ZAR</option>
                                    </select>
                                </div>

                                <label for="paystack_secret_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Paystack Secret key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_secret_key" name="gateway[Paystack][secret_key]" type="text"
                                        class="form-control" placeholder="{{ __('Paystack Secret key') }}"
                                        value="{{ $paymentGateway['Paystack']['secret_key'] ?? '' }}">
                                </div>

                                <label for="paystack_publishable_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Paystack Public key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_publishable_key" name="gateway[Paystack][api_key]" type="text"
                                        class="form-control" placeholder="{{ __('Paystack Public key') }}"
                                        value="{{ $paymentGateway['Paystack']['api_key'] ?? '' }}">
                                </div>

                                <label for="paystack_webhook_url"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('Paystack Webhook URL') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_webhook_url" name="gateway[Paystack][webhook_url]" type="text"
                                        class="form-control" placeholder="{{ __('Paystack Webhook URL') }}"
                                        value="{{ url('/webhook/paystack') }}" disabled>
                                </div>

                                <label class="col-sm-12 form-check-label  mt-2"
                                    id='lbl_stripe'>{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12  mt-2">
                                    <div class="form-check form-switch ">
                                        <input type="hidden" name="gateway[Paystack][status]" id="paystack_gateway"
                                            value="{{ $paymentGateway['Paystack']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['Paystack']['status']) && $paymentGateway['Paystack']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_paystack_gateway" aria-label="switch_paystack_gateway">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- Paystack Payment Gateway END --}}

                {{-- phonePe Payment Gateway START --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('PhonePe Setting') }}</h6>
                            </div>

                            <div class="form-group row mt-3">
                                <label for="paystack_secret_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Client Secret') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_secret_key" name="gateway[PhonePe][secret_key]" type="text"
                                        class="form-control phonepe-required"
                                        placeholder="{{ __('PhonePe Client Secret') }}"
                                        value="{{ $paymentGateway['PhonePe']['secret_key'] ?? '' }}">
                                </div>

                                <label for="paystack_publishable_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Client ID') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_publishable_key" name="gateway[PhonePe][api_key]" type="text"
                                        class="form-control phonepe-required" placeholder="{{ __('PhonePe Client ID') }}"
                                        value="{{ $paymentGateway['PhonePe']['api_key'] ?? '' }}">
                                </div>
                                <label for="paystack_publishable_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Client Version') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_publishable_key" name="gateway[PhonePe][additional_data_1]"
                                        type="text" class="form-control phonepe-required"
                                        placeholder="{{ __('PhonePe Client Version') }}"
                                        value="{{ $paymentGateway['PhonePe']['additional_data_1'] ?? '' }}">
                                </div>
                                 <label for="paystack_publishable_key"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Merchant ID') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_publishable_key" name="gateway[PhonePe][additional_data_2]"
                                        type="text" class="form-control phonepe-required"
                                        placeholder="{{ __('PhonePe Merchant ID') }}"
                                        value="{{ $paymentGateway['PhonePe']['additional_data_2'] ?? '' }}">
                                </div>
                                <label for="phonepe_username"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Username') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="phonepe_username_key" name="gateway[PhonePe][username]"
                                        type="text" class="form-control phonepe-required"
                                        placeholder="{{ __('PhonePe Username') }}"
                                        value="{{ $paymentGateway['PhonePe']['username'] ?? '' }}">
                                </div>
                                <label for="phonepe_password"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Password') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paystack_publishable_key" name="gateway[PhonePe][password]"
                                        type="text" class="form-control phonepe-required"
                                        placeholder="{{ __('PhonePe Password') }}"
                                        value="{{ $paymentGateway['PhonePe']['password'] ?? '' }}">
                                </div>

                                <label for="phonepe_mode"
                                    class="col-sm-12 form-check-label mt-2">{{ __('PhonePe Payment Mode') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select id="phonepe_mode" name="gateway[PhonePe][payment_mode]"
                                        class="form-control phonepe-required">
                                        <option value="UAT"
                                            {{ isset($paymentGateway['PhonePe']['payment_mode']) && $paymentGateway['PhonePe']['payment_mode'] == 'UAT' ? 'selected' : '' }}>
                                            UAT</option>
                                        <option value="PROD"
                                            {{ isset($paymentGateway['PhonePe']['payment_mode']) && $paymentGateway['PhonePe']['payment_mode'] == 'PROD' ? 'selected' : '' }}>
                                            PROD</option>
                                    </select>
                                </div>

                                <label for="paystack_webhook_url"
                                    class="col-sm-12 form-check-label  mt-2">{{ __('PhonePe Webhook URL') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="phonePe_webhook_url" name="gateway[PhonePe][webhook_url]" type="text"
                                        class="form-control" placeholder="{{ __('PhonePe Webhook URL') }}"
                                        value="{{ url('/webhook/phonePe') }}" disabled>
                                </div>

                                <label class="col-sm-12 form-check-label  mt-2"
                                    id='lbl_stripe'>{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12  mt-2">
                                    <div class="form-check form-switch ">
                                        <input type="hidden" name="gateway[PhonePe][status]" id="paystack_gateway"
                                            value="{{ $paymentGateway['PhonePe']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['PhonePe']['status']) && $paymentGateway['PhonePe']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_phonepe_gateway" aria-label="switch_paystack_gateway">
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                {{-- phonePe Payment Gateway END --}}

                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Flutterwave Setting') }}</h6>
                            </div>

                            <div class="form-group row mt-3">
                                <label for="flutterwave_currency_code" class="col-sm-12 form-check-label mt-2">
                                    {{ __('Flutterwave Currency') }}
                                </label>
                                <div class="col-sm-12 mt-2">
                                    <select name="gateway[flutterwave][currency_code]" id="flutterwave_currency_code"
                                        class="select2 form-select form-control-sm">
                                        <option value="NGN">NGN</option>
                                        <option value="USD">USD</option>
                                        <option value="GHS">GHS</option>
                                        <option value="KES">KES</option>
                                        <option value="UGX">UGX</option>
                                        <option value="TZS">TZS</option>
                                        <option value="ZAR">ZAR</option>
                                        <option value="XOF">XOF</option>
                                    </select>
                                </div>

                                <label for="flutterwave_secret_key" class="col-sm-12 form-check-label mt-2">
                                    {{ __('Flutterwave Secret Key') }}
                                </label>
                                <div class="col-sm-12 mt-2">
                                    <input id="flutterwave_secret_key" name="gateway[flutterwave][secret_key]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Flutterwave Secret Key') }}"
                                        value="{{ $paymentGateway['flutterwave']['secret_key'] ?? '' }}">
                                </div>

                                <label for="flutterwave_public_key" class="col-sm-12 form-check-label mt-2">
                                    {{ __('Flutterwave Public Key') }}
                                </label>
                                <div class="col-sm-12 mt-2">
                                    <input id="flutterwave_public_key" name="gateway[flutterwave][api_key]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Flutterwave Public Key') }}"
                                        value="{{ $paymentGateway['flutterwave']['api_key'] ?? '' }}">
                                </div>

                                <label for="flutterwave_encryption_key" class="col-sm-12 form-check-label mt-2">
                                    {{ __('Flutterwave  Webhook Secret') }}
                                </label>
                                <div class="col-sm-12 mt-2">
                                    <input id="flutterwave_encryption_key" name="gateway[flutterwave][webhook_secret_key]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Flutterwave Webhook Secret') }}"
                                        value="{{ $paymentGateway['flutterwave']['webhook_secret_key'] ?? '' }}">
                                </div>

                                <label for="flutterwave_webhook_url" class="col-sm-12 form-check-label mt-2">
                                    {{ __('Flutterwave Webhook URL') }}
                                </label>
                                <div class="col-sm-12 mt-2">
                                    <input id="flutterwave_webhook_url" name="gateway[flutterwave][webhook_url]"
                                        type="text" class="form-control"
                                        placeholder="{{ __('Flutterwave Webhook URL') }}"
                                        value="{{ url('/webhook/flutterwave') }}" disabled>
                                </div>

                                <label class="col-sm-12 form-check-label mt-2"
                                    id='lbl_flutterwave'>{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12 mt-2">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="gateway[flutterwave][status]"
                                            id="flutterwave_gateway"
                                            value="{{ $paymentGateway['flutterwave']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['flutterwave']['status']) && $paymentGateway['flutterwave']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_flutterwave_gateway" aria-label="switch_flutterwave_gateway">
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                {{-- paypal Payment Gateway START --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('PayPal Setting') }}</h6>
                            </div>
                            <div class="form-group row mt-3">

                                <label for="paypal_currency_code"
                                    class="col-sm-12 form-check-label mt-2">{{ __('PayPal Currency Symbol') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select name="gateway[Paypal][currency_code]" id="paypal_currency_code"
                                        class="select2 form-select form-control">
                                        <option value="USD"
                                            {{ ($paymentGateway['Paypal']['currency_code'] ?? '') == 'USD' ? 'selected' : '' }}>
                                            USD</option>
                                        <option value="EUR"
                                            {{ ($paymentGateway['Paypal']['currency_code'] ?? '') == 'EUR' ? 'selected' : '' }}>
                                            EUR</option>
                                        <option value="GBP"
                                            {{ ($paymentGateway['Paypal']['currency_code'] ?? '') == 'GBP' ? 'selected' : '' }}>
                                            GBP</option>
                                        <option value="AUD"
                                            {{ ($paymentGateway['Paypal']['currency_code'] ?? '') == 'AUD' ? 'selected' : '' }}>
                                            AUD</option>
                                        <option value="CAD"
                                            {{ ($paymentGateway['Paypal']['currency_code'] ?? '') == 'CAD' ? 'selected' : '' }}>
                                            CAD</option>
                                    </select>
                                </div>

                                <label for="paypal_client_id"
                                    class="col-sm-12 form-check-label mt-2">{{ __('PayPal Client ID') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paypal_client_id" name="gateway[Paypal][api_key]" type="text"
                                        class="form-control" placeholder="{{ __('PayPal Client ID') }}"
                                        value="{{ $paymentGateway['Paypal']['api_key'] ?? '' }}">
                                </div>

                                <label for="paypal_secret_key"
                                    class="col-sm-12 form-check-label mt-2">{{ __('PayPal Secret Key') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paypal_secret_key" name="gateway[Paypal][secret_key]" type="text"
                                        class="form-control" placeholder="{{ __('PayPal Secret Key') }}"
                                        value="{{ $paymentGateway['Paypal']['secret_key'] ?? '' }}">
                                </div>

                                <label for="paypal_webhook_url"
                                    class="col-sm-12 form-check-label mt-2">{{ __('PayPal Webhook URL') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <input id="paypal_webhook_url" name="gateway[Paypal][webhook_url]" type="text"
                                        class="form-control" placeholder="{{ __('PayPal Webhook URL') }}"
                                        value="{{ url('/webhook/paypal') }}" disabled>
                                </div>

                                <label for="phonepe_mode"
                                    class="col-sm-12 form-check-label mt-2">{{ __('Paypal Payment Mode') }}</label>
                                <div class="col-sm-12 mt-2">
                                    <select id="phonepe_mode" name="gateway[Paypal][payment_mode]"
                                        class="form-control phonepe-required">
                                        <option value="UAT"
                                            {{ isset($paymentGateway['Paypal']['payment_mode']) && $paymentGateway['Paypal']['payment_mode'] == 'UAT' ? 'selected' : '' }}>
                                            UAT</option>
                                        <option value="PROD"
                                            {{ isset($paymentGateway['Paypal']['payment_mode']) && $paymentGateway['Paypal']['payment_mode'] == 'PROD' ? 'selected' : '' }}>
                                            PROD</option>
                                    </select>
                                </div>

                                <label class="col-sm-12 form-check-label mt-2">{{ __('Status') }}</label>
                                <div class="col-sm-2 col-md-12 col-xs-12 mt-2">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="gateway[Paypal][status]" id="paypal_gateway"
                                            value="{{ $paymentGateway['Paypal']['status'] ?? 0 }}">
                                        <input class="form-check-input switch-input status-switch" type="checkbox"
                                            role="switch" name='op'
                                            {{ isset($paymentGateway['Paypal']['status']) && $paymentGateway['Paypal']['status'] == '1' ? 'checked' : '' }}
                                            id="switch_paypal_gateway" aria-label="switch_paypal_gateway">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- paypal Payment Gateway END --}}
                {{-- Bank Account Details --}}
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Manage Bank Account Details') }}</h6>
                            </div>

                            <div class="form-group">
                                <label for="account_holder_name"
                                    class="form-label">{{ __('Account Holder Name') }}</label>
                                <input class="form-control" type="text" name="bank[account_holder_name]"
                                    id="account_holder_name" value="{{ $settings['account_holder_name'] ?? '' }}">
                            </div>

                            <div class="form-group">
                                <label for="bank_name" class="form-label">{{ __('Bank Name') }}</label>
                                <input class="form-control" type="text" name="bank[bank_name]" id="bank_name"
                                    value="{{ $settings['bank_name'] ?? '' }}">
                            </div>

                            <div class="form-group">
                                <label for="account_number" class="form-label">{{ __('Account Number') }}</label>
                                <input class="form-control" type="number" name="bank[account_number]"
                                    id="account_number" value="{{ $settings['account_number'] ?? '' }}">
                            </div>

                            <div class="form-group">
                                <label for="ifsc_swift_code" class="form-label">{{ __('IFSC/SWIFT Code') }}</label>
                                <input class="form-control" type="text" name="bank[ifsc_swift_code]"
                                    id="ifsc_swift_code" value="{{ $settings['ifsc_swift_code'] ?? '' }}">
                            </div>

                            <label class="form-check-label mt-2">{{ __('Status') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="bank[bank_transfer_status]" value="0">
                                <input class="form-check-input" type="checkbox" name="bank[bank_transfer_status]"
                                    value="1"
                                    {{ isset($settings['bank_transfer_status']) && $settings['bank_transfer_status'] == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>


            </div>
            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
            </div>
        </form>
    </section>
@endsection

@section('script')
    <script type="text/javascript">
        $('#stripe_currency_code').val("{{ $paymentGateway['Stripe']['currency_code'] ?? '' }}").trigger("change");
        $('#switch_stripe_gateway').val("{{ $paymentGateway['Stripe']['status'] ?? false }}").trigger("change");

        $('#razorpay_currency_code').val("{{ $paymentGateway['Razorpay']['currency_code'] ?? '' }}").trigger("change");
        $('#switch_razorpay_gateway').val("{{ $paymentGateway['Stripe']['status'] ?? false }}").trigger("change");

        $('#paystack_currency_code').val("{{ $paymentGateway['Paystack']['currency_code'] ?? '' }}").trigger("change");
        $('#switch_paystack_gateway').val("{{ $paymentGateway['Stripe']['status'] ?? false }}").trigger("change");
    </script>
    <script>
        $(document).ready(function() {
            function togglePhonePeRequiredFields() {
                if ($('#switch_phonepe_gateway').is(':checked')) {
                    $('.phonepe-required').attr('required', true);
                } else {
                    $('.phonepe-required').removeAttr('required');
                }
            }

            // Initial check on page load
            togglePhonePeRequiredFields();

            // On switch toggle
            $('#switch_phonepe_gateway').on('change', function() {
                togglePhonePeRequiredFields();
            });
        });
    </script>
@endsection
