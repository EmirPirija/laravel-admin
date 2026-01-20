@extends('layouts.main')

@section('title')
    {{ __('Import Dummy Data') }}
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
    <div class="card">
        <div class="card-body">

            <div class="alert alert-warning" role="alert">
                <h5 class="alert-heading">
                    <i class="fas fa-exclamation-triangle"></i> {{ __('Important Notice') }}
                </h5>
                <hr>
                <p class="mb-0">
                    <strong>{{ __('Warning:') }}</strong> 
                    {{ __('This action will delete ALL categories and custom fields and insert fresh dummy data. This cannot be undone.') }}
                </p>
            </div>

            <div class="alert alert-info">
                <h6 class="alert-heading">
                    <i class="fas fa-info-circle"></i> {{ __('Instructions:') }}
                </h6>
                <ol class="mb-0">
                    <li>{{ __('Click the button below to import dummy data automatically.') }}</li>
                </ol>
            </div>

            <div class="mt-3 text-center">
                <button type="button" id="importDummyBtn" class="btn btn-danger btn-lg">
                    <i class="fas fa-database"></i> {{ __('Import Dummy Data') }}
                </button>
            </div>

        </div>
    </div>
</section>
@endsection
@section('script')
@section('script')
<script>
$(document).ready(function () {
    $('#importDummyBtn').on('click', function () {
        showSweetAlertForDataConfirmPopup(
            "{{ route('settings.dummy-data.import') }}",
            "POST",
            {
                text: "This action will delete ALL categories and custom fields and insert fresh dummy data. This cannot be undone.",
                confirmButtonText: "Yes, Delete & Import",
                data: {
                    _token: "{{ csrf_token() }}"
                },
                successCallBack: function () {
                }
            }
        );
    });
});
</script>
@endsection
