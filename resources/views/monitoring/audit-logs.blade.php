@extends('layouts.main')

@section('title')
    {{ __('Audit Log') }}
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
        <div class="card">
            <div class="card-body">
                <div id="toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <label for="auditActionFilter" class="mb-0 small text-muted">{{ __('Akcija') }}</label>
                        <input id="auditActionFilter"
                               type="text"
                               class="form-control form-control-sm"
                               style="max-width: 260px;"
                               placeholder="{{ __('Npr. customer_profile_deleted') }}">
                    </div>
                </div>
                <table class="table table-borderless table-striped"
                       id="table_list"
                       data-toggle="table"
                       data-url="{{ route('monitoring.audit.show') }}"
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
                       data-query-params="auditLogQueryParams"
                       data-escape="true"
                       data-mobile-responsive="true">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                        <th scope="col" data-field="actor_name" data-sortable="true">{{ __('Ko') }}</th>
                        <th scope="col" data-field="actor_email">{{ __('E-mail') }}</th>
                        <th scope="col" data-field="action" data-sortable="true">{{ __('Akcija') }}</th>
                        <th scope="col" data-field="target_type" data-sortable="true">{{ __('Tip') }}</th>
                        <th scope="col" data-field="target_id" data-sortable="true">{{ __('Target ID') }}</th>
                        <th scope="col" data-field="ip_address" data-sortable="true">{{ __('IP') }}</th>
                        <th scope="col" data-field="created_at_human" data-sortable="true">{{ __('Vrijeme') }}</th>
                        <th scope="col" data-field="context_preview">{{ __('Detalji') }}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </section>
@endsection

@section('js')
    <script>
        function auditLogQueryParams(p) {
            return Object.assign({}, p, {
                action: $('#auditActionFilter').val() || ''
            });
        }

        $(document).on('keyup', '#auditActionFilter', function () {
            $('#table_list').bootstrapTable('refresh');
        });
    </script>
@endsection
