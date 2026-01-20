@extends('layouts.main')

@section('title')
    {{ __("Translate Cities") }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
<section class="section">
    <form class="edit-form" action="{{ route('cities.translation.update') }}" method="POST" data-parsley-validate enctype="multipart/form-data">
        @csrf
        <div class="card">
            <div class="card-header">
                <h4>{{ __('Translate City Names') }}</h4>
            </div>
            <div class="card-body">
                @if($countries->isNotEmpty())
                        <div class="form-group mb-3">
                            <label for="country_translation">{{ __('Select Country') }}</label>
                            <select id="country_translation" class="form-control">
                                <option value="">{{ __('Select Country') }}</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->id }}">{{ $country->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <label for="state_translation">{{ __('Select State') }}</label>
                            <select id="state_translation" class="form-control" disabled>
                                <option value="">{{ __('Select State') }}</option>
                            </select>
                        </div>

                        <div id="city_translations_container" class="mt-4"></div>



                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">{{ __("Save") }}</button>
                    </div>
                @else
                    <p>{{ __("No countries found.") }}</p>
                @endif
            </div>
        </div>
    </form>
</section>
@endsection
