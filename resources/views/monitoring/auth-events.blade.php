@extends('layouts.main')

@section('title')
    {{ __('Auth Event Log') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row mb-3">
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Top IP signali (rate limit)') }}</h6>
                    </div>
                    <div class="card-body">
                        @if(($topIpSignals ?? collect())->isEmpty())
                            <p class="text-muted mb-0">{{ __('Nema podataka.') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>{{ __('IP') }}</th>
                                        <th class="text-end">{{ __('Hitovi') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($topIpSignals as $signal)
                                        <tr>
                                            <td>{{ $signal->ip_address }}</td>
                                            <td class="text-end">{{ $signal->hits }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Top identifikatori') }}</h6>
                    </div>
                    <div class="card-body">
                        @if(($topIdentifierSignals ?? collect())->isEmpty())
                            <p class="text-muted mb-0">{{ __('Nema podataka.') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Identifikator') }}</th>
                                        <th class="text-end">{{ __('Hitovi') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($topIdentifierSignals as $signal)
                                        <tr>
                                            <td>{{ \Illuminate\Support\Str::limit($signal->identifier, 28) }}</td>
                                            <td class="text-end">{{ $signal->hits }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0 pb-0">
                        <h6 class="mb-0">{{ __('Top endpointi') }}</h6>
                    </div>
                    <div class="card-body">
                        @if(($topEndpointSignals ?? collect())->isEmpty())
                            <p class="text-muted mb-0">{{ __('Nema podataka.') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Endpoint') }}</th>
                                        <th class="text-end">{{ __('Hitovi') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($topEndpointSignals as $signal)
                                        <tr>
                                            <td>{{ $signal->endpoint }}</td>
                                            <td class="text-end">{{ $signal->hits }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div id="toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <label for="authEventTypeFilter" class="mb-0 small text-muted">{{ __('Event') }}</label>
                        <input id="authEventTypeFilter"
                               type="text"
                               class="form-control form-control-sm"
                               style="max-width: 240px;"
                               placeholder="{{ __('Npr. otp_verify_failed') }}">

                        <label for="authEventStatusFilter" class="mb-0 small text-muted ms-2">{{ __('Status') }}</label>
                        <select id="authEventStatusFilter" class="form-select form-select-sm" style="max-width: 180px;">
                            <option value="">{{ __('Svi') }}</option>
                            <option value="info">info</option>
                            <option value="success">success</option>
                            <option value="warning">warning</option>
                            <option value="error">error</option>
                        </select>
                    </div>
                </div>

                <table class="table table-borderless table-striped"
                       id="table_list"
                       data-toggle="table"
                       data-url="{{ route('monitoring.auth.show') }}"
                       data-click-to-select="true"
                       data-side-pagination="server"
                       data-pagination="true"
                       data-page-list="[10, 25, 50, 100, 200]"
                       data-search="true"
                       data-show-columns="true"
                       data-show-refresh="true"
                       data-trim-on-search="false"
                       data-toolbar="#toolbar"
                       data-responsive="true"
                       data-sort-name="id"
                       data-sort-order="desc"
                       data-pagination-successively-size="3"
                       data-query-params="authEventQueryParams"
                       data-escape="true"
                       data-mobile-responsive="true">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                        <th scope="col" data-field="event_type" data-sortable="true">{{ __('Event') }}</th>
                        <th scope="col" data-field="status" data-sortable="true">{{ __('Status') }}</th>
                        <th scope="col" data-field="endpoint" data-sortable="true">{{ __('Endpoint') }}</th>
                        <th scope="col" data-field="user_name">{{ __('Korisnik') }}</th>
                        <th scope="col" data-field="user_email">{{ __('E-mail') }}</th>
                        <th scope="col" data-field="identifier" data-sortable="true">{{ __('Identifikator') }}</th>
                        <th scope="col" data-field="ip_address" data-sortable="true">{{ __('IP') }}</th>
                        <th scope="col" data-field="created_at_human" data-sortable="true">{{ __('Vrijeme') }}</th>
                        <th scope="col" data-field="meta_preview">{{ __('Meta') }}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </section>
@endsection

@section('js')
    <script>
        function authEventQueryParams(p) {
            return Object.assign({}, p, {
                event_type: $('#authEventTypeFilter').val() || '',
                status: $('#authEventStatusFilter').val() || ''
            });
        }

        $(document).on('keyup', '#authEventTypeFilter', function () {
            $('#table_list').bootstrapTable('refresh');
        });

        $(document).on('change', '#authEventStatusFilter', function () {
            $('#table_list').bootstrapTable('refresh');
        });
    </script>
@endsection
