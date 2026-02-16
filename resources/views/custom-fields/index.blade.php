@extends('layouts.main')
@section('title')
    {{__("Custom Fields")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 text-end">
                @can('custom-field-create')
                    <a href="{{ route('custom-fields.create', ['id' => 0]) }}" class="btn btn-primary mb-0">+ {{__("Create Custom Field")}} </a>
                    <button type="button" id="bulkEditCustomFieldsBtn" class="btn btn-outline-primary me-2">
                        Bulk Edit Selected
                    </button>

                    <a href="{{ route('custom-fields.bulk-upload') }}" class="btn btn-success mb-0 ms-2">
                        <i class="fas fa-upload"></i> {{__("Bulk Upload")}}
                    </a>
                @endcan

                @can('custom-field-update')
                    <button type="button" id="bulkEditBtn" class="btn btn-warning mb-0 ms-2" disabled>
                        <i class="fas fa-edit"></i> {{ __("Bulk Edit") }}
                    </button>
                @endcan
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <div id="filters">
                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <label for="filter">{{ __("Category") }}</label>
                                    <select id="customFieldCategoryFilter" class="form-control" aria-label="category">
                                        <option value="">{{ __("All") }}</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="type">{{ __("Type") }}</label>
                                    <select class="form-select form-control" id="customFieldTypeFilter">
                                        <option value="">{{ __("All") }}</option>
                                        <option value="number">{{ __("Number Input") }}</option>
                                        <option value="textbox">{{ __("Text Input") }}</option>
                                        <option value="fileinput">{{ __("File Input") }}</option>
                                        <option value="radio">{{ __("Radio") }}</option>
                                        <option value="dropdown">{{ __("Dropdown") }}</option>
                                        <option value="checkbox">{{ __("Checkboxes") }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <table class="stable-borderless table-striped" aria-describedby="mydesc" id="table_list"
                               data-toggle="table" data-url="{{ route('custom-fields.show',1) }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                               data-search="true" data-search-align="right" data-toolbar="#filters" data-show-columns="true"
                               data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1" data-fixed-right-number="1"
                               data-trim-on-search="false" data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-pagination-successively-size="3"
                               data-query-params="customFieldQueryParams"
                               data-escape="true"
                               data-show-export="true" data-export-options='{"fileName": "custom-field-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']'
                               data-mobile-responsive="true">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col" data-field="state" data-checkbox="true"></th>
                                <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                <th scope="col" data-field="image" data-align="center" data-formatter="imageFormatter">{{ __('Image') }}</th>
                                <th scope="col" data-field="name" data-align="center" data-escape="true" data-sortable="true">{{ __('Name') }}</th>
                                <th scope="col" data-field="category_names" data-align="center">{{ __('Category') }}</th>
                                <th scope="col" data-field="type" data-align="center" data-sortable="true">{{ __('Type') }}</th>
                                @canany(['custom-field-update','custom-field-delete'])
                                    <th scope="col" data-field="operate" data-escape="false" data-sortable="false">{{ __('Action') }}</th>
                                @endcanany
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @can('custom-field-update')
        <div class="modal fade" id="bulkEditModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form id="bulk-edit-form" action="{{ route('custom-fields.bulk-update') }}" method="POST">
                        @csrf

                        <div class="modal-header">
                            <h5 class="modal-title">{{ __("Bulk Edit Custom Fields") }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>

                        <div class="modal-body">
                            <p class="mb-3 text-muted">
                                <span id="bulkEditSelectedCount">0</span> {{ __("selected") }}.
                                {{ __("Only filled fields will be updated.") }}
                            </p>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">{{ __("Status") }}</label>
                                    <select class="form-select" name="status">
                                        <option value="">{{ __("No change") }}</option>
                                        <option value="1">{{ __("Active") }}</option>
                                        <option value="0">{{ __("Inactive") }}</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __("Required") }}</label>
                                    <select class="form-select" name="required">
                                        <option value="">{{ __("No change") }}</option>
                                        <option value="1">{{ __("Required") }}</option>
                                        <option value="0">{{ __("Optional") }}</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __("Priority") }}</label>
                                    <input type="number" class="form-control" name="priority" min="0"
                                           placeholder="{{ __('Leave empty for no change') }}">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __("Categories") }}</label>
                                    <select class="form-select" name="category_action" id="category_action">
                                        <option value="keep">{{ __("No change") }}</option>
                                        <option value="add">{{ __("Add categories") }}</option>
                                        <option value="remove">{{ __("Remove categories") }}</option>
                                        <option value="replace">{{ __("Replace categories") }}</option>
                                    </select>
                                </div>

                                <div class="col-md-8" id="bulkCategoriesWrap" style="display:none;">
                                    <label class="form-label">{{ __("Select categories") }}</label>
                                    <select class="form-control select2" name="categories[]" id="bulk_categories" multiple style="width: 100%;">
                                    </select>
                                    <small class="text-muted">{{ __("Used for Add/Remove/Replace actions.") }}</small>
                                </div>
                            </div>

                            <div id="bulk-ids-container"></div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                            <input type="submit" class="btn btn-warning" id="bulkEditSubmitBtn" value="{{ __('Apply') }}">
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
@endsection

@section('script')
    <script>
        function customFieldQueryParams(params) {
            params.category_id = $('#customFieldCategoryFilter').val() || '';
            params.type_filter = $('#customFieldTypeFilter').val() || '';
            return params;
        }
    </script>

    @can('custom-field-update')
        <script>
            $(document).ready(function () {
                const $table = $('#table_list');
                const $bulkBtn = $('#bulkEditBtn');

                const bulkModalEl = document.getElementById('bulkEditModal');
                const bulkModal = bulkModalEl ? new bootstrap.Modal(bulkModalEl) : null;

                function updateBulkBtnState() {
                    const selections = $table.bootstrapTable('getSelections') || [];
                    $bulkBtn.prop('disabled', selections.length === 0);
                }

                $table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table load-success.bs.table', function () {
                    updateBulkBtnState();
                });

                function toggleCategorySelect() {
                    const action = $('#category_action').val();
                    const needs = action && action !== 'keep';

                    $('#bulkCategoriesWrap').toggle(needs);
                    $('#bulk_categories').prop('disabled', !needs);
                }

                $(document).on('change', '#category_action', toggleCategorySelect);

                if ($('#customFieldCategoryFilter').length) {
                    $('#customFieldCategoryFilter').select2({
                        placeholder: "{{ __('Type to search category') }}",
                        allowClear: true,
                        width: '100%',
                        ajax: {
                            url: "{{ route('custom-fields.category-options.search') }}",
                            dataType: 'json',
                            delay: 250,
                            data: function (params) {
                                return {
                                    q: params.term || '',
                                    limit: 30
                                };
                            },
                            processResults: function (data) {
                                return { results: data.items || [] };
                            },
                            cache: true
                        },
                        minimumInputLength: 0
                    });
                }

                $('#customFieldTypeFilter').on('change', function () {
                    $table.bootstrapTable('refresh', {silent: true});
                });
                $('#customFieldCategoryFilter').on('change', function () {
                    $table.bootstrapTable('refresh', {silent: true});
                });

                if ($('#bulk_categories').length) {
                    $('#bulk_categories').select2({
                        dropdownParent: $('#bulkEditModal'),
                        placeholder: "{{ __('Type to search categories') }}",
                        width: '100%',
                        ajax: {
                            url: "{{ route('custom-fields.category-options.search') }}",
                            dataType: 'json',
                            delay: 250,
                            data: function (params) {
                                return {
                                    q: params.term || '',
                                    limit: 30
                                };
                            },
                            processResults: function (data) {
                                return { results: data.items || [] };
                            },
                            cache: true
                        },
                        minimumInputLength: 0
                    });
                }

                $bulkBtn.on('click', function () {
                    const selections = $table.bootstrapTable('getSelections') || [];
                    if (selections.length === 0) {
                        showErrorToast('Please select at least one custom field');
                        return;
                    }

                    // reset form first (da ne obriše IDs)
                    const formEl = document.getElementById('bulk-edit-form');
                    if (formEl) formEl.reset();
                    $('#bulk_categories').val(null).trigger('change');
                    $('#category_action').val('keep');
                    toggleCategorySelect();

                    $('#bulkEditSelectedCount').text(selections.length);

                    const $container = $('#bulk-ids-container');
                    $container.empty();
                    selections.forEach(function (row) {
                        $('<input>').attr({type: 'hidden', name: 'ids[]'}).val(row.id).appendTo($container);
                    });

                    if (bulkModal) bulkModal.show();
                });

                $('#bulk-edit-form').on('submit', function (e) {
                    e.preventDefault();

                    const $form = $(this);
                    const url = $form.attr('action');
                    const data = new FormData(this);

                    // koristi postojeći helper (function.js)
                    formAjaxRequest('PUT', url, data, $form, $('#bulkEditSubmitBtn'), function () {
                        $table.bootstrapTable('refresh');
                        if (bulkModal) bulkModal.hide();
                    });
                });

                updateBulkBtnState();
            });

            document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('bulkEditCustomFieldsBtn');
    if (!btn) return;

    btn.addEventListener('click', function () {
        const rows = $('#table_list').bootstrapTable('getSelections') || [];
        const ids = rows.map(r => r.id).filter(Boolean);

        if (!ids.length) {
            alert("Select at least one custom field.");
            return;
        }

        window.location.href = "{{ route('custom-fields.bulk-edit') }}" + "?ids=" + ids.join(',');
    });
});

        </script>
    @endcan
@endsection
