@extends('layouts.main')

@section('title')
    {{ __("Translate States") }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
<section class="section">
    <form class="edit-form" action="{{ route('states.translation.update') }}" method="POST" data-parsley-validate enctype="multipart/form-data">
        @csrf
         @method('PUT')
        <div class="card">
            <div class="card-header">
                <h4>{{ __('Translate State Names') }}</h4>
            </div>
            <div class="card-body">

                @if($countries->isNotEmpty())
                    <ul class="nav nav-tabs" id="countryTabs" role="tablist">
                        @foreach($countries as $index => $country)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link @if($index === 0) active @endif"
                                        id="country-tab-{{ $country->id }}"
                                        data-bs-toggle="tab"
                                        data-bs-target="#country-{{ $country->id }}"
                                        type="button" role="tab">
                                    {{ $country->name }}
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content border p-3 mt-3" id="countryTabContent">
                        @foreach($countries as $index => $country)
                            <div class="tab-pane fade @if($index === 0) show active @endif" id="country-{{ $country->id }}" role="tabpanel">
                                <h5 class="text-primary">{{ __('States in') }} {{ $country->name }}</h5>

                                @php
                                    $countryStates = $States->where('country_id', $country->id);
                                @endphp

                                @if($languages->isNotEmpty())
                                    <ul class="nav nav-tabs mt-3" id="languageTabs-{{ $country->id }}" role="tablist">
                                        @foreach($languages as $langIndex => $language)
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link @if($langIndex === 0) active @endif"
                                                        id="lang-tab-{{ $country->id }}-{{ $language->id }}"
                                                        data-bs-toggle="tab"
                                                        data-bs-target="#lang-content-{{ $country->id }}-{{ $language->id }}"
                                                        type="button" role="tab">
                                                    {{ $language->name }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="tab-content border p-3 mt-3">
                                        @foreach($languages as $langIndex => $language)
                                            <div class="tab-pane fade @if($langIndex === 0) show active @endif"
                                                 id="lang-content-{{ $country->id }}-{{ $language->id }}"
                                                 role="tabpanel">
                                                <h6 class="text-secondary">{{ __('Language') }}: {{ $language->name }}</h6>
                                                <div class="row">
                                                    @foreach($countryStates as $state)
                                                        @php
                                                            $existingTranslation = $state->translations->firstWhere('language_id', $language->id);
                                                        @endphp
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">{{ $state->name }}</label>
                                                            <input type="text"
                                                                   name="translations[{{ $language->id }}][{{ $state->id }}]"
                                                                   class="form-control"
                                                                   value="{{ $existingTranslation?->name }}"
                                                                   placeholder="{{ __('Enter name for') }} {{ $state->name }}">
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 text-end">
                        <input type="submit" class="btn btn-primary" value="{{ __('Save') }}">
                    </div>
                @else
                    <p>{{ __("No countries found.") }}</p>
                @endif
            </div>
        </div>
    </form>
</section>
@endsection
