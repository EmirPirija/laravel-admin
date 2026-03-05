@extends('layouts.main')

@section('title')
    {{ __('System Settings') }}
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
        <form class="create-form-without-reset" action="{{route('settings.store') }}" method="post" enctype="multipart/form-data" data-success-function="successFunction" data-parsley-validate>
            @csrf
            <div class="row d-flex mb-3">
                <div class="col-md-4 d-flex">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('Company Details') }}</h6>
                            </div>
                            <div class="row">
                                <div class="col-sm-12 form-group mandatory">
                                    <label for="company_name" class="col-sm-6 col-md-6 form-label mt-1">{{ __('Company Name') }}</label>
                                    <input name="company_name" type="text" class="form-control" id="company_name" placeholder="{{ __('Company Name') }}" value="{{ $settings['company_name'] ?? '' }}" required>
                                </div>
                                <div class="col-sm-12 form-group mandatory">
                                    <label for="company_email" class="col-sm-12 col-md-6 form-label mt-1">{{ __('Email') }}</label>
                                    <input id="company_email" name="company_email" type="email" class="form-control" placeholder="{{ __('Email') }}" value="{{ $settings['company_email'] ?? '' }}" required>
                                </div>

                                <div class="col-sm-12 form-group mandatory">
                                    <label for="company_tel1" class="col-sm-12 col-md-6 form-label mt-1">{{ __('Contact Number')." 1" }}</label>
                                    <input id="company_tel1" name="company_tel1" type="text" class="form-control" placeholder="{{ __('Contact Number')." 1" }}" maxlength="16" onKeyDown="if(this.value.length==16 && event.keyCode!=8) return false;" value="{{ $settings['company_tel1'] ?? '' }}" required>
                                </div>

                                <div class="col-sm-12">
                                    <label for="company_tel2" class="col-sm-12 col-md-6 form-label mt-1">{{ __('Contact Number')." 2" }}</label>
                                    <input id="company_tel2" name="company_tel2" type="text" class="form-control" placeholder="{{ __('Contact Number')." 2" }}" maxlength="16" onKeyDown="if(this.value.length==16 && event.keyCode!=8) return false;" value="{{ $settings['company_tel2'] ?? '' }}">
                                </div>

                                <div class="col-sm-12">
                                    <label for="company_address" class="col-sm-12 col-md-6 form-label mt-1">{{ __('Address') }}</label>
                                    <textarea id="company_address" name="company_address" type="text" class="form-control" placeholder="{{ __('Address') }}">{{ $settings['company_address'] ?? '' }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8 d-flex">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('More Setting') }}</h6>
                            </div>

                            <div class="row">
                                <div class="form-group col-sm-12 col-md-6 col-xs-12 mandatory">
                                    <label for="default_language" class="form-label ">{{ __('Default Language') }}</label>
                                    <select name="default_language" id="default_language" class="form-select form-control-sm">
                                        @foreach ($languages as $row)
                                            {{ $row }}
                                            <option value="{{ $row->code }}"
                                                {{ $settings['default_language'] == $row->code ? 'selected' : '' }}>
                                                {{ $row->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-6 col-xs-12 mandatory">
                                    <label for="currency_symbol" class="form-label">{{ __('Currency Symbol') }}</label>
                                    <input id="currency_symbol" name="currency_symbol" type="text" class="form-control" placeholder="{{ __('Currency Symbol') }}" value="{{ $settings['currency_symbol'] ?? '' }}" required="">
                                </div>
                                <div class="form-group col-sm-12 col-md-6 col-xs-12">
                                    <label for="currency_symbol_position" class="form-label">{{ __('Currency Symbol Position') }}</label>
                                    <div class="mt-2 d-flex align-items-center">
                                        <div class="form-check me-3">
                                            <input
                                                type="radio"
                                                id="currency_symbol_left"
                                                name="currency_symbol_position"
                                                value="left"
                                                class="form-check-input"
                                                {{ $settings['currency_symbol_position'] === 'left' ? 'checked' : '' }}
                                            >
                                            <label for="currency_symbol_left" class="form-check-label">{{ __('Left') }}</label>
                                        </div>
                                        <div class="form-check">
                                            <input
                                                type="radio"
                                                id="currency_symbol_right"
                                                name="currency_symbol_position"
                                                value="right"
                                                class="form-check-input"
                                                {{ $settings['currency_symbol_position'] === 'right' ? 'checked' : '' }}
                                            >
                                            <label for="currency_symbol_right" class="form-check-label">{{ __('Right') }}</label>
                                        </div>
                                    </div>
                                </div>


                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="android_version" class="form-label ">{{ __('Android Version') }}</label>
                                    <input id="android_version" name="android_version" type="text" class="form-control" placeholder="{{ __('Android Version') }}" value="{{ $settings['android_version']?? '' }}" required="">
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="play_store_link" class="form-label ">{{ __('Play Store Link') }}</label>
                                    <input id="play_store_link" name="play_store_link" type="url" class="form-control" placeholder="{{ __('Play Store Link') }}" value="{{ $settings['play_store_link'] ?? '' }}">
                                </div>


                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="ios_version" class="form-label ">{{ __('IOS Version') }}</label>
                                    <input id="ios_version" name="ios_version" type="text" class="form-control" placeholder="{{ __('IOS Version') }}" value="{{ $settings['ios_version'] ?? '' }}" required="">
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="app_store_link" class="form-label ">{{ __('App Store Link') }}</label>
                                    <input id="app_store_link" name="app_store_link" type="url" class="form-control" placeholder="{{ __('App Store Link') }}" value="{{ $settings['app_store_link'] ?? '' }}">
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-label ">{{ __('Maintenance Mode') }}</label>
                                    <i class="fa fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Temporary disable website.') }}" aria-label="{{ __('Temporary disable website.') }}"></i>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="maintenance_mode" id="maintenance_mode" class="checkbox-toggle-switch-input" value="{{ $settings['maintenance_mode'] ?? 0 }}">
                                        <input class="form-check-input checkbox-toggle-switch" type="checkbox" role="switch" {{ $settings['maintenance_mode'] == '1' ? 'checked' : '' }} id="switch_maintenance_mode">
                                        <label class="form-check-label" for="switch_maintenance_mode"></label>
                                    </div>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-label">{{ __('Force Update') }}</label>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="force_update" id="force_update" class="checkbox-toggle-switch-input" value="{{ (int)$settings['force_update'] ?? 0 }}">
                                        <input class="form-check-input checkbox-toggle-switch" type="checkbox" role="switch" {{ (int)$settings['force_update'] === 1 ? 'checked' : '' }} id="switch_force_update">
                                        <label class="form-check-label" for="switch_force_update"></label>
                                    </div>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-check-label">{{ __('Free Ad Listing') }}
                                        <i class="fa fa-info-circle"  data-bs-toggle="tooltip"  data-bs-placement="top"  title="{{ __('User can post ad without purchasing a package.') }}"  aria-label="{{ __('User can post ad without purchasing a package.') }}"></i>
                                    </label>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="free_ad_listing" id="free_ad_listing" class="checkbox-toggle-switch-input" value="{{ $settings['free_ad_listing'] ?? 0 }}">
                                        <input class="form-check-input checkbox-toggle-switch" type="checkbox" role="switch" id="switch_Free_ad_listing" aria-label="switch_Free_ad_listing" data-bs-toggle="tooltip" data-bs-placement="top" {{ $settings['free_ad_listing'] == '1' ? 'checked' : '' }}>
                                    </div>
                                </div>

                                <div class="form-group col-sm-12 col-md-6 col-xs-12">
                                    <label for="min_value" class="form-label">{{ __('Min Range') }}
                                        <i class="fa fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Adding fixed minimum range for radius.') }}" aria-label="{{ __('Adding fixed minimum range for radius.') }}"></i>
                                    </label>
                                    <input id="min_length" name="min_length" type="number" class="form-control"
                                           placeholder="{{ __('Enter Min Length') }}"
                                           value="{{ $settings['min_length'] ?? '' }}" >
                                </div>
                                <div class="form-group col-sm-12 col-md-6 col-xs-12">
                                    <label for="max_value" class="form-label">{{ __('Max Range') }}
                                        <i class="fa fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Adding fixed maximum range for radius.') }}" aria-label="{{ __('Adding fixed maximum range for radius.') }}"></i>
                                    </label>
                                    <input id="max_length" name="max_length" type="number" class="form-control"
                                           placeholder="{{ __('Enter Max Length') }}"
                                           value="{{ $settings['max_length'] ?? '' }}" >
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-check-label">{{ __('Auto Approve Advertisements') }}
                                        <i class="fa fa-info-circle"  data-bs-toggle="tooltip"  data-bs-placement="top"  title="{{ __('Item will be auto approved for all users.') }}"  aria-label="{{ __('Item will be auto approved for all users.') }}"></i>
                                    </label>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="auto_approve_item" id="auto_approve_item" class="checkbox-toggle-switch-input" value="{{ $settings['auto_approve_item'] ?? 0 }}">
                                        <input class="form-check-input checkbox-toggle-switch" type="checkbox" role="switch" id="switch_auto_approve_item" aria-label="switch_Free_ad_listing" data-bs-toggle="tooltip" data-bs-placement="top" {{ $settings['auto_approve_item'] == '1' ? 'checked' : '' }}>
                                    </div>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-check-label">{{ __('Auto Approve Edited Advertisements') }}
                                        <i class="fa fa-info-circle"  data-bs-toggle="tooltip"  data-bs-placement="top"  title="{{ __('Edited item will be auto approved for all users.') }}"  aria-label="{{ __('Edited item will be auto approved for all users.') }}"></i>
                                    </label>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="auto_approve_edited_item" id="auto_approve_edited_item" class="checkbox-toggle-switch-input" value="{{ $settings['auto_approve_edited_item'] ?? 0 }}">
                                        <input class="form-check-input checkbox-toggle-switch" type="checkbox" role="switch" id="switch_auto_approve_edited_item" aria-label="switch_Free_ad_listing" data-bs-toggle="tooltip" data-bs-placement="top" {{ $settings['auto_approve_edited_item'] == '1' ? 'checked' : '' }}>
                                    </div>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="map_provider" class="col-sm-12 form-check-label  mt-2">{{ __('Map Provider') }}</label>
                                    <select name="map_provider" id="map_provider" class="form-select form-control-sm">
                                        <option value="google_places" {{ !empty($settings['map_provider']) && $settings['map_provider'] == 'google_places' ? 'selected' : '' }}>{{ __('Place API') }}</option>
                                        <option value="free_api" {{ !empty($settings['map_provider']) && $settings['map_provider'] == 'free_api' ? 'selected' : '' }}>{{ __('Free API') }}</option>
                                        {{-- <option value="google_places">Plcae API</option>
                                        <option value="free_api">Free API</option> --}}
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-6" id="s3_div" style="display: none">
                                        <label for="place_api_key" class="form-label">{{ __('Place API Key') }}</label>
                                        <input class="form-control" type="text" name="place_api_key" id="place_api_key" value="{{ $settings['place_api_key'] ?? '' }}">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Admin User Credentials for Advertisement Creation') }}</h6>
                    </div>
                    <div class="row">
                        <div class="form-group col-sm-12 col-md-6">
                            <label for="admin_user_email" class="form-label">{{ __('Email') }}
                                <i class="fa fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Email address for admin to create advertisements on behalf of users.') }}" aria-label="{{ __('Email address for admin to create advertisements on behalf of users.') }}"></i>
                            </label>
                            <input id="admin_user_email" name="admin_user_email" type="email" class="form-control" placeholder="{{ __('Email') }}" value="{{ $settings['admin_user_email'] ?? '' }}">
                        </div>
                        <div class="form-group col-sm-12 col-md-6">
                            <label for="admin_user_password" class="form-label">{{ __('Password') }}
                                <i class="fa fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Password for admin to create advertisements on behalf of users. Leave blank to keep current password.') }}" aria-label="{{ __('Password for admin to create advertisements on behalf of users.') }}"></i>
                            </label>
                            <div class="input-group">
                                <input id="admin_user_password" name="admin_user_password" type="password" class="form-control" placeholder="{{ __('Leave blank to keep current password') }}" autocomplete="new-password" value="{{ $settings['admin_user_password'] ?? '' }}">
                                {{-- <button class="btn btn-outline-secondary" type="button" id="toggle-password-visibility">
                                    <i class="fa fa-eye" id="password-eye-icon"></i>
                                </button> --}}
                            </div>
                            @if(!empty($settings['admin_user_password']))
                                <small class="text-muted d-block mt-1">
                                    <i class="fa fa-lock"></i> {{ __('Password is set (hidden for security)') }}
                                </small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Images') }}</h6>
                    </div>

                    <div class="row">
                        <div class="form-group col-md-4 col-sm-12">
                            <label class=" col-form-label ">{{ __('Favicon Icon') }}</label>
                            <input class="filepond" type="file" name="favicon_icon" id="favicon_icon">
                            <img src="{{ $settings['favicon_icon'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/favicon.png')}}" class="mt-2 favicon_icon" alt="image" style=" height: 31%;width: 21%;">
                        </div>

                        <div class="form-group col-md-4 col-sm-12">
                            <label class="form-label ">{{ __('Company Logo') }}</label>
                            <input class="filepond" type="file" name="company_logo" id="company_logo">
                            <img src="{{ $settings['company_logo'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/logo.png')}}" class="mt-2 company_logo" alt="image" style="height: 31%;width: 21%;">
                        </div>

                        <div class="form-group col-md-4 col-sm-12">
                            <label class="form-label ">{{ __('Login Page Image') }}</label>
                            <input class="filepond" type="file" name="login_image" id="login_image">
                            <img src="{{ $settings['login_image'] ?? ''  }}" data-custom-image="{{asset('assets/images/bg/login.jpg')}}" class="mt-2 login_image" alt="image" style="height: 31%;width: 21%;">
                        </div>
                        {{-- <div class="form-group col-md-4 col-sm-12">
                            <label class="form-label ">{{ __('Watermark Image') }}</label>
                            <input class="filepond" type="file" name="watermark_image" id="watermark_image">
                            <img src="{{ $settings['watermark_image'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/watermark.png')}}" class="mt-2 watermark_image" alt="image" style="height: 31%;width: 21%;">
                        </div> --}}
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Deep Link') }}</h6>
                    </div>
                    {{-- <div class="form-group row mt-3">
                        <div class="col-md-6 col-sm-12">
                            <label for="deep_link_text_file" class="form-label">{{ __('Apple App Site Association File') }}</label>
                            <input id="deep_link_text_file" name="deep_link_text_file" type="file" class="form-control">
                            <p style="display: none" id="img_error_msg" class="badge rounded-pill bg-danger"></p>
                        </div>
                        <div class="col-md-6 col-sm-12">
                            <label for="deep_link_json_file" class="form-label">{{ __('Assetlinks File') }}</label>
                            <input id="deep_link_json_file" name="deep_link_json_file" type="file" class="form-control">
                            <p style="display: none" id="img_error_msg" class="badge rounded-pill bg-danger"></p>
                        </div>
                    </div> --}}
                    <div class="form-group row mt-3">
                        <div class="col-md-12">
                            <label for="scheme" class="form-label">{{ __('Deep Link Scheme') }}</label>
                            <input id="scheme" name="deep_link_scheme" type="text" class="form-control" placeholder="e.g., myapp"
                                   pattern="^[a-z][a-z0-9]*$"
                                   title="{{ __('Must start with a letter, lowercase, and contain no spaces or special characters.') }}" value="{{ $settings['deep_link_scheme'] ?? '' }}">
                                   <small class="text-muted d-block mt-1">
                                    {{ __('Must start with a letter, be lowercase, and contain no spaces or special characters.') }}
                                </small><small class="text-muted">{{ __('Example: ') }}<strong>myapp://</strong></small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Authentication Setting (Enable/Disable)') }}</h6>
                    </div>
                    <div class="form-group row mt-3">
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Mobile Authentication') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="mobile_authentication" value="0">
                                <input class="form-check-input auth" type="checkbox" id="mobile_authentication" name="mobile_authentication" value="1" {{ isset($settings['mobile_authentication']) && $settings['mobile_authentication'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="google_authentication">
                                    {{ __('On / Off') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Google Authentication') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="google_authentication" value="0">
                                <input class="form-check-input auth" type="checkbox" id="google_authentication" name="google_authentication" value="1" {{ isset($settings['google_authentication']) && $settings['google_authentication'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="google_authentication">
                                    {{ __('On / Off') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Email Authentication') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="email_authentication" value="0">
                                <input class="form-check-input auth" type="checkbox" id="email_authentication" name="email_authentication" value="1" {{ isset($settings['email_authentication']) && $settings['email_authentication'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="email_authentication">
                                    {{ __('On / Off') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Apple Authentication') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="apple_authentication" value="0">
                                <input class="form-check-input auth" type="checkbox" id="email_authentication" name="apple_authentication" value="1" {{ isset($settings['apple_authentication']) && $settings['apple_authentication'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="apple_authentication">
                                    {{ __('On / Off') }}
                                </label>
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
        
        // Toggle password visibility for admin user password
        $(document).on('click', '#toggle-password-visibility', function() {
            const passwordInput = $('#admin_user_password');
            const eyeIcon = $('#password-eye-icon');
            
            if (passwordInput.attr('type') === 'password') {
                passwordInput.attr('type', 'text');
                eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                passwordInput.attr('type', 'password');
                eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });
    </script>
@endsection
