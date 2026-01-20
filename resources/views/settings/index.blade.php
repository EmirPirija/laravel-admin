@extends('layouts.main')

@section('title')
    {{ __('Settings') }}
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
        <div class="row">
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.system') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class="fas fa-cogs text-dark icon_font_size "></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Settings') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.web-settings') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class="fas fa-cog text-dark icon_font_size "></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Web Settings') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.notification-setting') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class="fas fa-bell text-dark icon_font_size "></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Notification Settings') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.login-method') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class="fas fa-bell text-dark icon_font_size "></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('OTP Provider Settings') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.dummy-data.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class="fas fa-database text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Import Dummy Data') }}</h5>
                        <div class="">{{ __('Add dummy categories and custom fields') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.admob.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fas fa-ad text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Admob') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.about-us.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fas fa-info-circle text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('About Us') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.terms-conditions.index') }}" class="card setting_active_tab h-100"
                   style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class=" fas fa-file-contract text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Terms & Conditions') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.privacy-policy.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class=" fas fas fa-shield-alt text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Privacy Policy') }}</h5>
                        <div class="{{ route('settings.privacy-policy.index') }}">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i></div>
                    </div>

                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.refund-policy.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class=" fas fas fa-shield-alt text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Refund Policy') }}</h5>
                        <div class="{{ route('settings.refund-policy.index') }}">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i></div>
                    </div>

                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.contact-us.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <i class=" fas fas fa-address-book text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Contact Us') }}</h5>
                        <div class="{{ route('settings.contact-us.index') }}">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i></div>
                    </div>

                </a>
            </div>

            {{--            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">--}}
            {{--                <a href="{{ route('settings.firebase.index') }}" class="card setting_active_tab h-100"--}}
            {{--                   style="text-decoration: none;">--}}
            {{--                    <div class="content d-flex h-100">--}}
            {{--                        <div class="row mx-2 ">--}}
            {{--                            <div class="provider_a test   ">--}}
            {{--                                <i class=" fas fa-cloud text-dark icon_font_size"></i>--}}
            {{--                            </div>--}}
            {{--                        </div>--}}
            {{--                    </div>--}}

            {{--                    <div class="card-body">--}}
            {{--                        <h5 class="title">{{ __('Firebase Settings') }}</h5>--}}
            {{--                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>--}}
            {{--                        </div>--}}
            {{--                    </div>--}}
            {{--                </a>--}}
            {{--            </div>--}}

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.language.index') }}" class="card setting_active_tab h-100"
                   style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class=" fas fas fa-language text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Languages') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.payment-gateway.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fas fa-dollar-sign text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Payment Gateways') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.system-status.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fas fa-external-link-square-alt text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('System Status') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.seo-settings.index') }}" class="card setting_active_tab h-100"
                   style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fab fa-searchengin text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Seo-Settings') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.file-manager.index') }}" class="card setting_active_tab h-100"
                   style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test">
                                <FontAwesomeIcon icon="" />
                                <i class="fas fa-file-export text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('File Manager') }}</h5>
                        <div class="">{{ __('Go to settings') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>

            @hasrole('Super Admin')
            <div class="col-xxl-3 col-xl-4 col-lg-6 col-md-12 mb-3">
                <a href="{{ route('settings.error-logs.index') }}" class="card setting_active_tab h-100" style="text-decoration: none;">
                    <div class="content d-flex h-100">
                        <div class="row mx-2 ">
                            <div class="provider_a test   ">
                                <i class="fa fa-file-alt text-dark icon_font_size"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <h5 class="title">{{ __('Log Viewer') }}</h5>
                        <div class="">{{ __('Find Errors in your System') }} <i class="fas fa-arrow-right mt-2 arrow_icon"></i>
                        </div>
                    </div>
                </a>
            </div>
            @endhasrole
        </div>
    </section>
@endsection
