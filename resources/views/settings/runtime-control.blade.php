@extends('layouts.main')

@section('title')
    {{ __('Runtime Control') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>{{ __('Runtime Control') }}</h4>
                <p class="text-muted mb-0">{{ __('Upravljaj ponašanjem sistema bez redeploy-a frontenda.') }}</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first text-md-end">
                <span class="badge bg-primary fs-6">{{ __('Config Version') }}: {{ $version }}</span>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row gy-3">
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <strong>{{ __('Napomena:') }}</strong>
                    {{ __('Svaki save automatski povećava runtime verziju. Frontend učitava novu konfiguraciju u runtime-u.') }}
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Core Runtime Settings') }}</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.runtime-control.settings') }}" method="POST">
                            @csrf
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label fw-bold">maintenance_json</label>
                                    <textarea name="maintenance_json" class="form-control font-monospace" rows="12">{{ $maintenance_json }}</textarea>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label fw-bold">services_json</label>
                                    <textarea name="services_json" class="form-control font-monospace" rows="12">{{ $services_json }}</textarea>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label fw-bold">ad_controls_json</label>
                                    <textarea name="ad_controls_json" class="form-control font-monospace" rows="12">{{ $ad_controls_json }}</textarea>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label fw-bold">promo_banners_json</label>
                                    <textarea name="promo_banners_json" class="form-control font-monospace" rows="12">{{ $promo_banners_json }}</textarea>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label fw-bold">client_defaults_json</label>
                                    <textarea name="client_defaults_json" class="form-control font-monospace" rows="12">{{ $client_defaults_json }}</textarea>
                                </div>
                            </div>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save Core Settings') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Feature Flags') }}</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.runtime-control.feature-flags') }}" method="POST">
                            @csrf
                            <label class="form-label fw-bold">feature_flags_json (JSON array)</label>
                            <textarea name="feature_flags_json" class="form-control font-monospace" rows="16">{{ $feature_flags_json }}</textarea>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save Feature Flags') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Announcements') }}</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.runtime-control.announcements') }}" method="POST">
                            @csrf
                            <label class="form-label fw-bold">announcements_json (JSON array)</label>
                            <textarea name="announcements_json" class="form-control font-monospace" rows="16">{{ $announcements_json }}</textarea>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save Announcements') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Plan Limits') }}</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.runtime-control.plan-limits') }}" method="POST">
                            @csrf
                            <label class="form-label fw-bold">plan_limits_json (JSON array)</label>
                            <textarea name="plan_limits_json" class="form-control font-monospace" rows="14">{{ $plan_limits_json }}</textarea>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save Plan Limits') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('User Limit Overrides') }}</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('settings.runtime-control.user-overrides') }}" method="POST">
                            @csrf
                            <label class="form-label fw-bold">user_overrides_json (JSON array)</label>
                            <textarea name="user_overrides_json" class="form-control font-monospace" rows="14">{{ $user_overrides_json }}</textarea>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save User Overrides') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Runtime Preview (Guest)') }}</h5>
                    </div>
                    <div class="card-body">
                        <textarea class="form-control font-monospace" rows="18" readonly>{{ $runtime_preview_json }}</textarea>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
