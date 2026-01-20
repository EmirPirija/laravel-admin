@extends('layouts.main')

@section('title')
    {{ __('Web Settings') }}
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
            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Web Settings') }}</h6>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-6 col-sm-12">
                            <label for="web_theme_color" class="form-label ">{{ __('Theme Color') }}</label>
                            <input id="web_theme_color" name="web_theme_color" type="color" class="form-control form-control-color" placeholder="{{ __('Theme Color') }}" value="{{ $settings['web_theme_color'] ?? '' }}">
                        </div>
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Header Logo') }}</label>
                            <input class="filepond" type="file" name="header_logo" id="header_logo">
                            <img src="{{ $settings['header_logo'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/Header Logo.svg')}}" class="w-25" alt="image">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Footer Logo') }}</label>
                            <input class="filepond" type="file" name="footer_logo" id="footer_logo">
                            <img src="{{ $settings['footer_logo'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/Footer Logo.svg')}}" class="w-25" alt="image">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Placeholder image') }} <small>{{__('(This image will be displayed if no image is available.)')}}</small></label>
                            <input class="filepond" type="file" name="placeholder_image" id="placeholder_image">
                            <img src="{{ $settings['placeholder_image'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/favicon.png')}}" alt="image" style="height: 31%;width: 21%;">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="footer_description" class="form-label ">{{ __('Footer Description') }}</label>
                            <textarea id="footer_description" name="footer_description" class="form-control" rows="5" placeholder="{{ __('Footer Description') }}">{{ $settings['footer_description'] ?? '' }}</textarea>
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="google_map_iframe_link" class="form-label ">{{ __('Google Map Iframe Link') }}</label>
                            <textarea id="google_map_iframe_link" name="google_map_iframe_link" type="text" class="form-control" rows="5" placeholder="{{ __('Google Map Iframe Link') }}">{{ $settings['google_map_iframe_link'] ?? '' }}</textarea>
                        </div>

                         @if($languages_translate->isNotEmpty())
                        <div class="col-md-12 mt-3">
                            <hr>
                            <h5>{{ __("Translations") . " (" . __("Optional") . ")" }}</h5>
                        </div>

                        @foreach($languages_translate as $language)
                            <div class="col-md-6 mb-4 p-3 rounded">
                                <h6 class="mb-3 text-primary">
                                    {{ __("Translation for") }}: <strong>{{ $language->name }} ({{ $language->code }})</strong>
                                </h6>

                                <input type="hidden" name="translations[{{ $language->id }}][name]" value="footer_description">

                                <div class="form-group">
                                    <label for="translation_{{ $language->id }}" class="form-label">
                                        {{ __("Translated Footer Description") }}
                                    </label>
                                    <textarea class="form-control"
                                              name="translations[{{ $language->id }}][value]"
                                              rows="4"
                                              placeholder="{{ __('Contact Us in') . ' ' . $language->name }}">
                                        {{ old("translations.{$language->id}.value", $translations['footer_description'][$language->id] ?? '') }}
                                    </textarea>
                                </div>
                            </div>
                        @endforeach
                    @endif

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="google_map_iframe_link" class="form-label ">{{ __('Default Latitude & Longitude') }} <small>{{__('(For Default Location Selection)')}}</small></label>
                            <div class="form-group">
                                <label for="default_latitude" class="form-label ">{{ __('Latitude') }}</label>
                                <input id="default_latitude" name="default_latitude" type="text" class="form-control" placeholder="{{ __('Latitude') }}" value="{{ $settings['default_latitude'] ?? '' }}">
                                <label for="default_longitude" class="form-label ">{{ __('Longitude') }}</label>
                                <input id="default_longitude" name="default_longitude" type="text" class="form-control" placeholder="{{ __('Longitude') }}" value="{{ $settings['default_longitude'] ?? '' }}">
                            </div>
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Show Landing Page') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="show_landing_page" value="0">
                                <input class="form-check-input" type="checkbox" id="show_landing_page" name="show_landing_page" value="1" {{ isset($settings['show_landing_page']) && $settings['show_landing_page'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="show_landing_page">
                                    {{ __('On / Off') }}
                                </label>
                            </div>
                        </div>


                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Social Media Links') }}</h6>
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="instagram_link" class="form-label ">{{ __('Instagram Link') }}</label>
                        <input id="instagram_link" name="instagram_link" type="url" class="form-control" placeholder="{{ __('Instagram Link') }}" value="{{ $settings['instagram_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="x_link" class="form-label ">{{ __('X Link') }}</label>
                        <input id="x_link" name="x_link" type="url" class="form-control" placeholder="{{ __('X Link') }}" value="{{ $settings['x_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="facebook_link" class="form-label ">{{ __('Facebook Link') }}</label>
                        <input id="facebook_link" name="facebook_link" type="url" class="form-control" placeholder="{{ __('Facebook Link') }}" value="{{ $settings['facebook_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="linkedin_link" class="form-label ">{{ __('Linkedin Link') }}</label>
                        <input id="linkedin_link" name="linkedin_link" type="url" class="form-control" placeholder="{{ __('Linkedin Link') }}" value="{{ $settings['linkedin_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="pinterest_link" class="form-label ">{{ __('Pinterest Link') }}</label>
                        <input id="pinterest_link" name="pinterest_link" type="url" class="form-control" placeholder="{{ __('Pinterest Link') }}" value="{{ $settings['pinterest_link'] ?? '' }}">
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
