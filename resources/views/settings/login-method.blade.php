@extends('layouts.main')

@section('title')
    {{ __('OTP Provider Settings') }}
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
        <form class="create-form-without-reset" action="{{ route('settings.store') }}" method="post" enctype="multipart/form-data" data-success-function="successFunction" data-parsley-validate>
            @csrf
            <div class="row d-flex mb-3">
                <div class="card mb-0">
                    <div class="card-body">
                        <div class="divider pt-3">
                            <h6 class="divider-text">{{ __('OTP Provider Settings') }}</h6>
                        </div>
                        {{-- OTP Services Provider --}}
                        <div class="form-group row mt-3" id="otp-services-provider-div">
                            <label class="col-sm-12 form-label-mandatory" for="otp-services-provider">{{ __('OTP Services Provider') }}</label>
                            <div class="col-md-6 col-sm-12">
                                <select name="otp_service_provider" id="otp-services-provider" class="choosen-select form-select form-control-sm otp-services-provider">
                                    <option value="firebase" {{ !empty($settings['otp_service_provider']) && $settings['otp_service_provider'] == 'firebase' ? 'selected' : '' }}>{{ __('Firebase') }}</option>
                                    <option value="twilio" {{ !empty($settings['otp_service_provider']) && $settings['otp_service_provider'] == 'twilio' ? 'selected' : '' }}>{{ __('Twilio') }}</option>
                                </select>
                            </div>
                        </div>

                        {{-- Twilio SMS Settings --}}
                        <div class="col-12 mt-2 p-4 row bg-light" id="twilio-sms-settings-div">
                            <h5>{{ __('Twilio SMS Settings') }}</h5>

                            <div class="form-group row mt-3">
                                <div class="col-md-6 col-sm-12">
                                    <label for="twilio_account_sid" class="form-label">{{ __('Account SID') }}</label>
                                    <input type="text" name="twilio_account_sid" id="twilio_account_sid" class="form-control" placeholder="{{ __('Account SID') }}" value="{{ $settings['twilio_account_sid'] ?? '' }}">
                                </div>
                                <div class="col-md-6 col-sm-12">
                                    <label for="twilio_auth_token" class="form-label">{{ __('Auth Token') }}</label>
                                    <input type="text" name="twilio_auth_token" id="twilio_auth_token" class="form-control" placeholder="{{ __('Auth Token') }}" value="{{ $settings['twilio_auth_token'] ?? '' }}">
                                </div>
                            </div>

                            <div class="form-group row mt-3">
                                <div class="col-md-6 col-sm-12">
                                    <label for="twilio_my_phone_number" class="form-label">{{ __('My Twilio Phone Number') }}</label>
                                    <input type="text" name="twilio_my_phone_number" id="twilio_my_phone_number" class="form-control" placeholder="{{ __('My Twilio Phone Number') }}" value="{{ $settings['twilio_my_phone_number'] ?? '' }}">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" value="btnAdd" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
            </div>
        </form>
    </section>
@endsection

@section('js')
    <script>
        function successFunction() {
            window.location.reload();
        }
    </script>
@endsection
