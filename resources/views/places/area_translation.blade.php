@extends('layouts.main')

@section('title')
    {{ __("Translate Areas") }}
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
    <form class="edit-form" action="{{ route('areas.translation.update') }}" method="POST" data-parsley-validate>
        @csrf
        <div class="card">
            <div class="card-header">
                <h4>{{ __('Translate Area Names') }}</h4>
            </div>
            <div class="card-body">
                @if($countries->isNotEmpty())
                    <div class="form-group mb-3">
                        <label for="country_translation_area">{{ __('Select Country') }}</label>
                        <select id="country_translation_area" class="form-control">
                            <option value="">{{ __('Select Country') }}</option>
                            @foreach($countries as $country)
                                <option value="{{ $country->id }}">{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label for="State_For_area">{{ __('Select State') }}</label>
                        <select id="State_For_area" class="form-control" disabled>
                            <option value="">{{ __('Select State') }}</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label for="city_translation">{{ __('Select City') }}</label>
                        <select id="city_translation" class="form-control" disabled>
                            <option value="">{{ __('Select City') }}</option>
                        </select>
                    </div>

                    <div id="area_translations_container" class="mt-4"></div>

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
@section('script')
<script>
$('#country_translation_area').on('change', function () {
    console.log('here');
    let countryId = $(this).val();
    let url = window.baseurl + 'states/search?country_id=' + countryId;

    $('#State_For_area').html("<option value=''>{{ __('Select State') }}</option>");
    $('#city_translation').html("<option value=''>{{ __('Select City') }}</option>").prop('disabled', true);
    $('#State_For_area').prop('disabled', true);
    $('#area_translations_container').html("");

    if (!countryId) return;

    ajaxRequest('GET', url, null, null, function (response) {
        $.each(response.data, function (key, value) {
            console.log(
                response
            );

            $('#State_For_area').append($('<option>', { value: value.id, text: value.name }));
        });
        $('#State_For_area').prop('disabled', false);
    });
});

$('#State_For_area').on('change', function () {
    let stateId = $(this).val();
    $('#city_translation').html("<option value=''>{{ __('Select City') }}</option>").prop('disabled', true);
    $('#area_translations_container').html("");

    if (!stateId) return;

    let url = window.baseurl + 'cities/search?state_id=' + stateId;

    ajaxRequest('GET', url, null, null, function (response) {
        $.each(response.data, function (key, value) {
            $('#city_translation').append($('<option>', { value: value.id, text: value.name }));
        });
        $('#city_translation').prop('disabled', false);
    });
});

$('#city_translation').on('change', function () {
    let cityId = $(this).val();
    $('#area_translations_container').html("");

    if (!cityId) return;

    let url = window.baseurl + 'area-translations/' + cityId;

    $.ajax({
        url: url,
        type: 'GET',
        success: function (response) {
            $('#area_translations_container').html(response);
        },
        error: function () {
            $('#area_translations_container').html('<div class="text-danger">Failed to load translations.</div>');
        }
    });
});
</script>
@endsection
