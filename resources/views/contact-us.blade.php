@extends('layouts.main')

@section('title')
    {{ __('Kontakt poruke') }}
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
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Ukupno') }}</div>
                        <div class="h4 mb-0">{{ (int) ($stats->total ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Novo') }}</div>
                        <div class="h4 mb-0 text-secondary">{{ (int) ($stats->new_count ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('U obradi') }}</div>
                        <div class="h4 mb-0 text-warning">{{ (int) ($stats->in_progress_count ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Zatvoreno') }}</div>
                        <div class="h4 mb-0 text-success">{{ (int) ($stats->closed_count ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div id="toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <label for="contactStatusFilter" class="mb-0 small text-muted">{{ __('Status') }}</label>
                        <select id="contactStatusFilter" class="form-select form-select-sm" style="max-width: 220px;">
                            <option value="">{{ __('Svi statusi') }}</option>
                            <option value="new">{{ __('Novo') }}</option>
                            <option value="in_progress">{{ __('U obradi') }}</option>
                            <option value="closed">{{ __('Zatvoreno') }}</option>
                        </select>
                    </div>
                </div>

                <table class="table table-borderless table-striped"
                       id="table_list"
                       data-toggle="table"
                       data-url="{{ route('contact-us.show') }}"
                       data-click-to-select="true"
                       data-side-pagination="server"
                       data-pagination="true"
                       data-page-list="[10, 25, 50, 100, 200]"
                       data-search="true"
                       data-search-align="right"
                       data-toolbar="#toolbar"
                       data-show-columns="true"
                       data-show-refresh="true"
                       data-trim-on-search="false"
                       data-responsive="true"
                       data-sort-name="id"
                       data-sort-order="desc"
                       data-pagination-successively-size="3"
                       data-query-params="contactInboxQueryParams"
                       data-escape="true"
                       data-use-row-attr-func="true"
                       data-mobile-responsive="true"
                       data-show-export="true"
                       data-export-options='{"fileName": "contact-inbox","ignoreColumn": ["operate"]}'
                       data-export-types="['pdf','json','xml','csv','txt','sql','doc','excel']">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                        <th scope="col" data-field="name" data-sortable="true">{{ __('Ime') }}</th>
                        <th scope="col" data-field="email" data-sortable="true">{{ __('E-mail') }}</th>
                        <th scope="col" data-field="phone" data-sortable="true">{{ __('Telefon') }}</th>
                        <th scope="col" data-field="subject" data-sortable="true">{{ __('Naslov') }}</th>
                        <th scope="col" data-field="message" data-formatter="descriptionFormatter">{{ __('Poruka') }}</th>
                        <th scope="col" data-field="status_badge" data-escape="false">{{ __('Status') }}</th>
                        <th scope="col" data-field="assigned_to_name" data-sortable="true">{{ __('Dodijeljeno') }}</th>
                        <th scope="col" data-field="resolved_at_human">{{ __('Zatvoreno') }}</th>
                        <th scope="col" data-field="created_at_human">{{ __('Kreirano') }}</th>
                        <th scope="col" data-field="operate" data-escape="false" data-align="center">{{ __('Akcija') }}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>

        <div class="modal fade" id="contactStatusModal" tabindex="-1" aria-labelledby="contactStatusModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="contactStatusForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="contactStatusModalLabel">{{ __('Ažuriraj status poruke') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Zatvori') }}"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="contactStatusId" name="id" value="">
                            <div class="mb-3">
                                <label for="contactStatusValue" class="form-label">{{ __('Status') }}</label>
                                <select id="contactStatusValue" name="status" class="form-select" required>
                                    <option value="new">{{ __('Novo') }}</option>
                                    <option value="in_progress">{{ __('U obradi') }}</option>
                                    <option value="closed">{{ __('Zatvoreno') }}</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label for="contactAdminNote" class="form-label">{{ __('Admin napomena') }}</label>
                                <textarea id="contactAdminNote" name="admin_note" class="form-control" rows="4" maxlength="2000" placeholder="{{ __('Npr. korisnik kontaktiran, čeka odgovor...') }}"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Odustani') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Sačuvaj') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('js')
    <script>
        function contactInboxQueryParams(p) {
            return Object.assign({}, p, {
                status: $('#contactStatusFilter').val() || ''
            });
        }

        $(document).on('change', '#contactStatusFilter', function () {
            $('#table_list').bootstrapTable('refresh');
        });

        $(document).on('click', '.contact-update', function () {
            const id = $(this).data('id');
            const status = $(this).data('status') || 'new';
            const note = $(this).data('note') || '';

            $('#contactStatusId').val(id);
            $('#contactStatusValue').val(status);
            $('#contactAdminNote').val(note);
        });

        $(document).on('submit', '#contactStatusForm', function (e) {
            e.preventDefault();
            const id = $('#contactStatusId').val();
            if (!id) {
                showErrorToast("{{ __('Nedostaje ID poruke.') }}");
                return;
            }

            const url = "{{ route('contact-us.update-status', ['id' => '__id__']) }}".replace('__id__', String(id));
            const payload = {
                status: $('#contactStatusValue').val(),
                admin_note: $('#contactAdminNote').val() || '',
                _token: $('meta[name="csrf-token"]').attr('content')
            };

            $.ajax({
                url,
                type: 'POST',
                data: payload,
                success: function (response) {
                    if (response && response.error === false) {
                        showSuccessToast(response.message || "{{ __('Status je ažuriran.') }}");
                        $('#contactStatusModal').modal('hide');
                        $('#table_list').bootstrapTable('refresh');
                        return;
                    }
                    showErrorToast((response && response.message) ? response.message : "{{ __('Ažuriranje nije uspjelo.') }}");
                },
                error: function (xhr) {
                    const message = xhr?.responseJSON?.message || "{{ __('Ažuriranje nije uspjelo.') }}";
                    showErrorToast(message);
                }
            });
        });
    </script>
@endsection
