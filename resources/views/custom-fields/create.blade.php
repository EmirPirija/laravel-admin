@extends('layouts.main')

@section('title')
    {{__("Custom Fields")}}
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
        <form action="{{ route('custom-fields.store') }}" method="POST" class="create-form" data-success-function="afterCustomFieldCreationSuccess" data-parsley-validate enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header">{{__("Create Custom Field")}}</div>
                        <div class="card-body mt-2">

                            <ul class="nav nav-tabs" id="langTabs" role="tablist">
                                @foreach($languages as $key => $lang)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link @if($key == 0) active @endif" id="tab-{{ $lang->id }}" data-bs-toggle="tab" data-bs-target="#lang-{{ $lang->id }}" type="button" role="tab">
                                            {{ $lang->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach($languages as $key => $lang)
                                    <div class="tab-pane fade @if($key == 0) show active @endif" id="lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Field Name') }} ({{ $lang->name }})</label>
                                            <input type="text" name="name[{{ $lang->id }}]" class="form-control" @if($lang->id != 1) required @endif>
                                        </div>

                                        @if($lang->id == 1)
                                            {{-- Type (Only in English) --}}
                                            <div class="form-group">
                                                <label>{{ __('Field Type') }}</label>
                                                <select name="type" class="form-control" required>
                                                    <option value="number">{{ __("Number Input") }}</option>
                                                    <option value="textbox">{{ __("Text Input") }}</option>
                                                    <option value="fileinput">{{ __("File Input") }}</option>
                                                    <option value="radio">{{ __("Radio") }}</option>
                                                    <option value="dropdown">{{ __("Dropdown") }}</option>
                                                    <option value="checkbox">{{ __("Checkboxes") }}</option>
                                                </select>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 form-group min-max-fields">
                                                    <label>{{ __('Field Length (Min)') }}</label>
                                                    <input type="number" name="min_length" class="form-control">
                                                </div>
                                                <div class="col-md-6 form-group min-max-fields">
                                                    <label>{{ __('Field Length (Max)') }}</label>
                                                    <input type="number" name="max_length" class="form-control">
                                                </div>
                                            </div>
                                        @else
                                            <div class="alert alert-info mt-2">
                                                {{ __('Field type, min/max length, required and status can only be set in English.') }}
                                            </div>
                                        @endif

                                        <div class="form-group">
                                            <label>{{ __('Field Values') }} ({{ $lang->name }})</label>
                                            <select name="values[{{ $lang->id }}][]" data-tags="true" data-placeholder="{{ __("Select an option") }}" data-allow-clear="true"  data-token-separators="[',']" class="select2 w-100 full-width-select2" multiple="multiple" @if($lang->id == 1) required @endif></select>
                                            @if($lang->id != 1)
                                                <small class="text-muted">{{ __('Used for translatable field types.') }}</small>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="col-md-12 form-group mandatory">
                                        <label for="image" class="form-label">{{ __('Icon ') }}</label>
                                        <input type="file" name="image" id="image" class="form-control" data-parsley-required="true" accept=" .jpg, .jpeg, .png, .svg">
                                        {{__("(use 256 x 256 size for better view)")}}
                                        <div class="img_error" style="color:#DC3545;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group mandatory">
                                    <div class="form-check form-switch  ">
                                        <input type="hidden" name="required" id="required" value="0">
                                        <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="required">{{ __('Required') }}
                                        <label class="form-check-label" for="required"></label>
                                    </div>
                                </div>
                                <div class="col-md-6 form-group mandatory">
                                    <div class="form-check form-switch  ">
                                        <input type="hidden" name="status" id="status" value="0">
                                        <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="status">{{ __('Active') }}
                                        <label class="form-check-label" for="status"></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @if ($cat_id == 0)
                    <div class="col-md-6 col-sm-12">
                        <div class="card">
                            <div class="card-header">{{__("Category")}}</div>
                            <div class="card-body mt-2">
                                <div class="sub_category_lit">
                                    @foreach ($categories as $category)
                                        <div class="category">
                                            <div class="category-header">
                                                <label>
                                                    <input type="checkbox" name="selected_categories[]" value="{{ $category->id }}"> {{ $category->name }}
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
                                                    <i style='font-size:24px' class='fas toggle-button'>{!! $arrowIcon !!}</i>
                                                @endif
                                            </div>
                                            <div class="subcategories" style="display: none;">
                                                @if (!empty($category->subcategories))
                                                    @include('category.treeview', ['categories' => $category->subcategories])
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <input type="hidden" name="selected_categories[]" value="{{ $cat_id }}">
                @endif
                <div class="col-md-12 text-end">
                    <input type="submit" class="btn btn-primary" value="{{__("Save and Back")}}">
                </div>
            </div>
        </form>
    </section>
@endsection
@section('js')
    <script>
                function updateCustomFieldUI() {
            const type = $('select[name="type"]').val();
            const valuesTypes = ['radio', 'dropdown', 'checkbox'];

            $('.tab-pane').each(function () {
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

        $(document).ready(function () {
            updateCustomFieldUI();

            $(document).on('change', 'select[name="type"]', function () {
                updateCustomFieldUI();
            });
        });

      function afterCustomFieldCreationSuccess() {
        setTimeout(function () {
            window.location.href = "{{ route('custom-fields.index') }}";
        }, 1000)
    }
    </script>

@endsection
