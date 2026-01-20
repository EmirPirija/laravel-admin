@extends('layouts.main')

@section('title')
    {{ __("System Status")." ".__("Settings")}}
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
<div class="row mb-3">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="divider pt-3">
                    <h6 class="divider-text">{{ __('System Status') }}</h6>
                </div>

                <!-- Backend Section -->
                <div class="card mt-3">
                    <div class="card-header">
                        {{ __('Backend') }}
                    </div>
                </div>

                <!-- Storage Section -->
                <div class="card mt-1">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center">
                                <label for="Storage" class="form-label mb-0 me-2">
                                    {{ __('Storage') }}
                                </label>
                                <a href="#" data-bs-toggle="tooltip" title="{{ $isLinked ? __('Storage folder is currently linked.') : __('Storage folder is not linked. Click to link it.') }}">
                                    <i class="bi bi-question-circle"></i>
                                </a>
                            </div>

                            <div class="d-flex align-items-center">
                                <i class="bi {{ $isLinked ? 'bi-check-circle text-success' : 'bi-x-circle text-danger' }} me-2" aria-label="{{ $isLinked ? 'Linked' : 'Unlinked' }}"></i>

                                <form action="{{ route('toggle.storage.link') }}" method="POST" class="m-0">
                                    @csrf
                                    <button type="submit" class="btn btn-link text-primary" style="font-size: 0.875rem; padding: 0; text-decoration: underline;">
                                        {{ __('Link') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
@section('script')
<script>
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();
});
</script>
@endsection
