@extends('layouts.main')

@section('title')
    {{ __('Failed Jobs') }}
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
            <div class="col-md-4 col-12">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Trenutno neuspjelih jobova') }}</div>
                        <div class="h3 mb-0 {{ ($failedJobsCount ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                            {{ (int) ($failedJobsCount ?? 0) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="table table-borderless table-striped"
                       id="table_list"
                       data-toggle="table"
                       data-url="{{ route('monitoring.failed.show') }}"
                       data-click-to-select="true"
                       data-side-pagination="server"
                       data-pagination="true"
                       data-page-list="[10, 25, 50, 100, 200]"
                       data-search="true"
                       data-show-columns="true"
                       data-show-refresh="true"
                       data-trim-on-search="false"
                       data-responsive="true"
                       data-sort-name="id"
                       data-sort-order="desc"
                       data-pagination-successively-size="3"
                       data-query-params="queryParams"
                       data-escape="true"
                       data-mobile-responsive="true">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                        <th scope="col" data-field="connection" data-sortable="true">{{ __('Konekcija') }}</th>
                        <th scope="col" data-field="queue" data-sortable="true">{{ __('Queue') }}</th>
                        <th scope="col" data-field="payload_hash">{{ __('Payload hash') }}</th>
                        <th scope="col" data-field="error_preview">{{ __('Greška') }}</th>
                        <th scope="col" data-field="failed_at" data-sortable="true">{{ __('Vrijeme') }}</th>
                        <th scope="col" data-field="operate" data-escape="false" data-align="center">{{ __('Ponovi') }}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </section>
@endsection

@section('js')
    <script>
        $(document).on('click', '.retry-failed-job', function () {
            const id = $(this).data('id');
            if (!id) {
                showErrorToast("{{ __('Nedostaje ID job-a.') }}");
                return;
            }

            const url = "{{ route('monitoring.failed.retry', ['id' => '__id__']) }}".replace('__id__', String(id));
            $.ajax({
                url,
                type: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content')
                },
                success: function (response) {
                    if (response && response.error === false) {
                        showSuccessToast(response.message || "{{ __('Job je vraćen na retry red.') }}");
                        $('#table_list').bootstrapTable('refresh');
                        return;
                    }
                    showErrorToast((response && response.message) ? response.message : "{{ __('Retry nije uspio.') }}");
                },
                error: function (xhr) {
                    const message = xhr?.responseJSON?.message || "{{ __('Retry nije uspio.') }}";
                    showErrorToast(message);
                }
            });
        });
    </script>
@endsection
