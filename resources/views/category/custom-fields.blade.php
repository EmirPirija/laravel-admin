@extends('layouts.main')

@section('title')
    {{__("Custom Fields")}} / {{__("Sub Category")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row">
            <div class="col-md-10">
                <div class="buttons text-start">
                    <a href="{{ route('category.index', $p_id) }}" class="btn btn-primary">< {{__("Back To Category")}} </a>
                    <a href="{{ route('custom-fields.create', ['id' => $cat_id]) }}" class="btn btn-primary">+ {{__("Create Custom Field")}} / {{ $category_name }}</a>
                </div>
            </div>
        </div>

        <div class="col-md-12 col-sm-12 mt-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">{{ __('Assign Existing Custom Fields') }}</h6>
                    <p class="text-muted mb-3">{{ __('Search existing fields and assign them to this category without opening each field manually.') }}</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-10">
                            <label for="assign_custom_fields" class="form-label">{{ __('Custom Fields') }}</label>
                            <select id="assign_custom_fields" class="form-control" multiple></select>
                        </div>
                        <div class="col-md-2">
                            <button id="assign_custom_fields_btn" type="button" class="btn btn-success w-100">
                                {{ __('Assign') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-12 col-sm-12">
            <div class="card">
                <div class="card-body">
                    <table class="table table-borderless table-striped" aria-describedby="mydesc" id="table_list"
                           data-toggle="table" data-url="{{ route('category.custom-fields.show', $cat_id) }}"
                           data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                           data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-search-align="right"
                           data-escape="true"
                           data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                           data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false" data-responsive="true"
                           data-sort-name="id" data-sort-order="desc" data-pagination-successively-size="3"
                           data-query-params="queryParams" data-mobile-responsive="true">
                        <thead class="thead-dark">
                        <tr>
                            <th scope="col" data-field="state" data-checkbox="true"></th>
                            <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                            <th scope="col" data-field="image" data-align="center" data-formatter='imageFormatter'>{{ __('Image') }}</th>
                            <th scope="col" data-field="name" data-align="center" data-sortable="true">{{ __('Custom Field') }}</th>
                            <th scope="col" data-field="operate" data-escape="false" data-sortable="false">{{ __('Action') }}</th>
                        </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        $(function () {
            const $assignSelect = $('#assign_custom_fields');
            const $assignBtn = $('#assign_custom_fields_btn');
            const table = $('#table_list');

            $assignSelect.select2({
                placeholder: "{{ __('Type to search custom fields') }}",
                width: '100%',
                ajax: {
                    url: "{{ route('category.custom-fields.search', $cat_id) }}",
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term || '',
                            limit: 30
                        };
                    },
                    processResults: function (data) {
                        return {
                            results: data.items || []
                        };
                    },
                    cache: true
                },
                minimumInputLength: 0
            });

            $assignBtn.on('click', function () {
                const selected = $assignSelect.val() || [];
                if (!selected.length) {
                    showErrorToast("{{ __('Select at least one custom field.') }}");
                    return;
                }

                $assignBtn.prop('disabled', true);
                $.ajax({
                    url: "{{ route('category.custom-fields.assign', $cat_id) }}",
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: "{{ csrf_token() }}",
                        custom_field_ids: selected
                    },
                    success: function (response) {
                        showSuccessToast(response.message || "{{ __('Assigned successfully.') }}");
                        $assignSelect.val(null).trigger('change');
                        table.bootstrapTable('refresh');
                    },
                    error: function (xhr) {
                        const message = xhr?.responseJSON?.message || xhr?.responseJSON?.error || "{{ __('Something went wrong.') }}";
                        showErrorToast(message);
                    },
                    complete: function () {
                        $assignBtn.prop('disabled', false);
                    }
                });
            });
        });
    </script>
@endsection
