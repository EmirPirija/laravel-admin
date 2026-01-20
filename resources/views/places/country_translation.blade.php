@extends('layouts.main')

@section('title')
    {{ __("Translate Countries") }}
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
    <form class="edit-form" action="{{route('countries.translation.update')}}" method="POST" data-parsley-validate enctype="multipart/form-data">
        @csrf
        <div class="card">
            <div class="card-header">
                <h4>{{ __('Translate Country Names') }}</h4>
            </div>
            <div class="card-body">
                @if($languages->isNotEmpty())
                    <ul class="nav nav-tabs" id="languageTabs" role="tablist">
                        @foreach($languages as $index => $language)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link @if($index === 0) active @endif" id="tab-{{ $language->id }}" data-bs-toggle="tab" data-bs-target="#lang-{{ $language->id }}" type="button" role="tab">
                                    {{ $language->name }}
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="tab-content border p-3 mt-3" id="languageTabContent">
                        @foreach($languages as $index => $language)
                            <div class="tab-pane fade @if($index === 0) show active @endif" id="lang-{{ $language->id }}" role="tabpanel">
                                <h5 class="text-primary mb-3">{{ __("Translations for") }}: {{ $language->name }} ({{ $language->code }})</h5>
                                <div class="row">
                                    @foreach($countries as $country)

                                            <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                {{ $country->name }}
                                            </label>
                                            @php
                                            $existingTranslation = $country->nameTranslations->firstWhere('language_id', $language->id);
                                            @endphp
                                            <input type="text"
                                                name="translations[{{ $language->id }}][{{ $country->id }}]"
                                                class="form-control"
                                                value="{{ $existingTranslation ? $existingTranslation->name : '' }}"
                                                placeholder="{{ __('Enter name for') }} {{ $country->name }}">

                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 text-end">
                      <div class="col-md-12 m-2 text-end">
                                <input type="submit" class="btn btn-primary" value="{{__("Save")}}">
                        </div>
                    </div>
                @else
                    <p>{{ __("No languages found.") }}</p>
                @endif
            </div>
        </div>
    </form>
</section>
@endsection
