@extends('layouts.main')

@section('title')
    {{ __('Custom Fields') }}
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
        <div class="buttons">
            <a class="btn btn-primary" href="{{ url('custom-fields') }}">
                < {{ __('Back to Custom Fields') }} </a>
                    @if (in_array($custom_field->type, ['radio', 'checkbox', 'dropdown']))
                        <a class="btn btn-primary" data-bs-toggle="modal" data-bs-target='#addModal'>+
                            {{ __('Add Options') }}</a>
                    @endif
        </div>
        <form action="{{ route('custom-fields.update', $custom_field->id) }}" class="edit-form"
            data-success-function="afterCustomFieldUpdate" method="POST" data-parsley-validate
            enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="row">

                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header">{{ __('Edit Custom Field') }}</div>
                        <div class="card-body mt-2">

                            <ul class="nav nav-tabs" id="langTabs" role="tablist">
                                @foreach ($languages as $key => $lang)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link @if ($key == 0) active @endif"
                                            id="tab-{{ $lang->id }}" data-bs-toggle="tab"
                                            data-bs-target="#lang-{{ $lang->id }}" type="button" role="tab">
                                            {{ $lang->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach ($languages as $key => $lang)
                                    <div class="tab-pane fade @if ($key == 0) show active @endif"
                                        id="lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Field Name') }} ({{ $lang->name }})</label>
                                            <input type="text" name="name[{{ $lang->id }}]" class="form-control"
                                                value="{{ $translations[$lang->id]['name'] ?? '' }}"
                                                @if ($lang->id == 1) required @endif>
                                        </div>

                                        @if ($lang->id == 1)
                                            {{-- Type (Only in English) - Read Only --}}
                                            <div class="form-group">
                                                <label>{{ __('Field Type') }}</label>
                                                <select name="type" id="type" class="form-control" required
                                                    disabled>
                                                    <option value="number"
                                                        {{ $custom_field->type == 'number' ? 'selected' : '' }}>
                                                        {{ __('Number Input') }}</option>
                                                    <option value="textbox"
                                                        {{ $custom_field->type == 'textbox' ? 'selected' : '' }}>
                                                        {{ __('Text Input') }}</option>
                                                    <option value="fileinput"
                                                        {{ $custom_field->type == 'fileinput' ? 'selected' : '' }}>
                                                        {{ __('File Input') }}</option>
                                                    <option value="radio"
                                                        {{ $custom_field->type == 'radio' ? 'selected' : '' }}>
                                                        {{ __('Radio') }}</option>
                                                    <option value="dropdown"
                                                        {{ $custom_field->type == 'dropdown' ? 'selected' : '' }}>
                                                        {{ __('Dropdown') }}</option>
                                                    <option value="checkbox"
                                                        {{ $custom_field->type == 'checkbox' ? 'selected' : '' }}>
                                                        {{ __('Checkboxes') }}</option>
                                                </select>
                                                <input type="hidden" name="type" value="{{ $custom_field->type }}">
                                                <small
                                                    class="text-muted">{{ __('Field type cannot be changed after creation.') }}</small>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 form-group min-max-fields">
                                                    <label>{{ __('Field Length (Min)') }}</label>
                                                    <input type="number" name="min_length" class="form-control"
                                                        value="{{ $custom_field->min_length }}">
                                                </div>
                                                <div class="col-md-6 form-group min-max-fields">
                                                    <label>{{ __('Field Length (Max)') }}</label>
                                                    <input type="number" name="max_length" class="form-control"
                                                        value="{{ $custom_field->max_length }}">
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="col-md-12 form-group">
                                                        <label for="image"
                                                            class="form-label">{{ __('Icon ') }}</label>
                                                        <input type="file" name="image" id="image"
                                                            class="form-control" accept=" .jpg, .jpeg, .png, .svg">
                                                        {{ __('(use 256 x 256 size for better view)') }}
                                                        <div class="field_img mt-2">
                                                            <img src="{{ empty($custom_field->image) ? asset('assets/img_placeholder.jpeg') : $custom_field->image }}"
                                                                alt="" id="blah"
                                                                class="preview-image img w-25">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 form-group mandatory">
                                                    <div class="form-check form-switch  ">
                                                        <input type="hidden" name="required" id="required"
                                                            value="{{ $custom_field->required ? '1' : '0' }}">
                                                        <input class="form-check-input status-switch" type="checkbox"
                                                            role="switch" aria-label="required"
                                                            {{ $custom_field->required ? 'checked' : '' }}>{{ __('Required') }}
                                                        <label class="form-check-label" for="required"></label>
                                                    </div>
                                                </div>
                                                <div class="col-md-6 form-group mandatory">
                                                    <div class="form-check form-switch  ">
                                                        <input type="hidden" name="status" id="status"
                                                            value="{{ $custom_field->status ? '1' : '0' }}">
                                                        <input class="form-check-input status-switch" type="checkbox"
                                                            role="switch" aria-label="status"
                                                            {{ $custom_field->status ? 'checked' : '' }}>{{ __('Active') }}
                                                        <label class="form-check-label" for="status"></label>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="form-group">
                                            <label>{{ __('Field Values') }} ({{ $lang->name }})</label>
                                            <select name="values[{{ $lang->id }}][]" data-tags="true"
                                                data-placeholder="{{ __('Select an option') }}" data-allow-clear="true"
                                                data-token-separators="[',']" class="select2 w-100 full-width-select2"
                                                multiple="multiple" @if ($lang->id == 1) required @endif>
                                                @php
                                                    $fieldValues = [];
                                                    if ($lang->id == 1) {
                                                        $fieldValues = is_array($custom_field->values)
                                                            ? $custom_field->values
                                                            : [];
                                                    } elseif (
                                                        isset($translations[$lang->id]['value']) &&
                                                        is_array($translations[$lang->id]['value'])
                                                    ) {
                                                        $fieldValues = $translations[$lang->id]['value'];
                                                    }
                                                @endphp
                                                @foreach ($fieldValues as $value)
                                                    <option value="{{ $value }}" selected>{{ $value }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($lang->id != 1)
                                                <small
                                                    class="text-muted">{{ __('Used for translatable field types.') }}</small>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header">{{ __('Category') }}</div>
                        <div class="card-body mt-2">
                            <div class="sub_category_lit">
                                @foreach ($categories as $category)
                                    <div class="category">
                                        <div class="category-header">
                                            <label>
                                                <input type="checkbox" name="selected_categories[]"
                                                    value="{{ $category->id }}"
                                                    {{ in_array($category->id, $selected_categories) ? 'checked' : '' }}>
                                                {{ $category->name }}
                                            </label>
                                            @if (!empty($category->subcategories))
                                                @php
                                                    // Get current language from Session (not from foreach loop)
                                                    $currentLang = Session::get('language');
                                                    // Check RTL: use accessor which returns boolean (rtl != 0)
                                                    $isRtl = false;
                                                    if (!empty($currentLang)) {
                                                        try {
                                                            // Try to get raw attribute first, fallback to accessor
                                                            $rtlRaw = method_exists($currentLang, 'getRawOriginal') ? $currentLang->getRawOriginal('rtl') : null;
                                                            if ($rtlRaw !== null) {
                                                                $isRtl = ($rtlRaw == 1 || $rtlRaw === true);
                                                            } else {
                                                                $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                                                            }
                                                        } catch (\Exception $e) {
                                                            $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                                                        }
                                                    }
                                                    $arrowIcon = $isRtl ? '&#xf0d9;' : '&#xf0da;'; // fa-caret-left for RTL, fa-caret-right for LTR
                                                @endphp
                                                <i style="font-size:24px"
                                                    class="fas toggle-button {{ in_array($category->id, $selected_all_categories) ? 'open' : '' }}">
                                                    {!! $arrowIcon !!}
                                                </i>
                                            @endif
                                        </div>

                                        {{-- ✅ Show children open if parent or child is selected --}}
                                        <div class="subcategories"
                                            style="display: {{ in_array($category->id, $selected_all_categories) ? 'block' : 'none' }};">
                                            @if (!empty($category->subcategories))
                                                @include('category.treeview', [
                                                    'categories' => $category->subcategories,
                                                    'selected_categories' => $selected_categories,
                                                    'selected_all_categories' => $selected_all_categories,
                                                ])
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 text-end mb-3">
                    <input type="submit" class="btn btn-primary" value="{{ __('Save and Back') }}">
                </div>
            </div>
        </form>
        <!-- @if (in_array($custom_field->type, ['radio', 'checkbox', 'dropdown']))
    <div class="col-md-12 col-sm-12">
                    <div class="card">
                        <div class="card-body">
                            <table class="table table-borderless table-striped" id="table_list"
                                   data-toggle="table" data-url="{{ route('custom-fields.value.show', $custom_field->id) }}"
                                   data-click-to-select="true"
                                   data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-search-align="right"
                                   data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                                   data-trim-on-search="false" data-responsive="true" data-sort-name="id"
                                   data-escape="true"
                                   data-sort-order="desc" data-query-params="queryParams"
                                   data-table="custom_fields" data-use-row-attr-func="true" data-mobile-responsive="true">
                                <thead class="thead-dark">
                                <tr>
                                    <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="value" data-align="center" data-sortable="true">{{ __('Value') }}</th>
                                    <th scope="col" data-field="operate"data-escape="false" data-align="center" data-sortable="false" data-events="customFieldValueEvents">{{ __('Action') }}</th>
                                </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
    @endif -->
        {{-- add modal --}}
        @if (in_array($custom_field->type, ['radio', 'checkbox', 'dropdown']))
            <div id="addModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel1">{{ __('Add Values') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form action="{{ route('custom-fields.value.add', $custom_field->id) }}"
                                class="create-form form-horizontal" enctype="multipart/form-data" method="POST"
                                data-parsley-validate>
                                @csrf
                                <div class="col-md-12 form-group mandatory">
                                    <label for="values" class="mandatory form-label">{{ __('Field Values') }}</label>
                                    <input type="text" name="values" id="values" class="form-control"
                                        value="{{ old('values') }}" data-parsley-required="true">
                                </div>

                                <input type="hidden" name="field_id" id="field_id" value="{{ $custom_field->id }}">
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary waves-effect"
                                        data-bs-dismiss="modal">{{ __('Close') }}</button>
                                    <button type="submit"
                                        class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- /.modal-content -->
            </div>
            {{-- edit modal --}}
            <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel1">{{ __('Edit Custome Field Values') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form action="{{ route('custom-fields.value.update', $custom_field->id) }}"
                                class="edit-form form-horizontal" enctype="multipart/form-data" method="POST"
                                data-parsley-validate>
                                @csrf
                                <input type="hidden" name="old_custom_field_value" id="old_custom_field_value" />
                                <div class="col-md-12 form-group mandatory">
                                    <label for="new_custom_field_value"
                                        class="mandatory form-label">{{ __('Name') }}</label>
                                    <input type="text" name="new_custom_field_value" id="new_custom_field_value"
                                        class="form-control" value="" data-parsley-required="true">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary waves-effect"
                                        data-bs-dismiss="modal">{{ __('Close') }}</button>
                                    <button type="submit"
                                        class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- /.modal-content -->
        @endif
        </div>
    </section>
@endsection
@section('js')
    <script>
        window.existingTranslations = @json($translations ?? []);
        // console.log('Loaded Translations:', window.existingTranslations);
    </script>

    <script>
        function updateCustomFieldUI() {
            const type = $('select[name="type"]').val();
            const valuesTypes = ['radio', 'dropdown', 'checkbox'];

            $('.tab-pane').each(function() {
                const $tab = $(this);

                const $fieldValues = $tab.find('select[name^="values"]')
                    .closest('.form-group');

                const $minMaxGroup = $tab.find('.min-max-fields');

                if (valuesTypes.includes(type)) {
                    $fieldValues.show();
                    $minMaxGroup.hide();
                } else if (type === 'fileinput') {
                    $fieldValues.hide();
                    $minMaxGroup.hide();
                } else {
                    $fieldValues.hide();
                    $minMaxGroup.show();
                }
            });
        }

        $(document).ready(function() {
            updateCustomFieldUI();

            $(document).on('change', 'select[name="type"]', function() {
                updateCustomFieldUI();
            });

            // Initialize select2 for all value selects
            $('select[name^="values"]').each(function() {
                if (!$(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2({
                        tags: true,
                        tokenSeparators: [','],
                        placeholder: "{{ __('Select an option') }}",
                        allowClear: true
                    });
                }
            });

            // Image preview functionality
            $('#image').on('change', function() {
                const [file] = this.files;
                if (file) {
                    $('#blah').attr('src', URL.createObjectURL(file));
                }
            });
        });

        function afterCustomFieldUpdate() {
            setTimeout(function() {
                window.location.href = "{{ route('custom-fields.index') }}"
            }, 1000)
        }
    </script>
@endsection
