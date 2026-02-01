@extends('layouts.main')

@section('title')
    {{ __('Bulk Edit Custom Fields') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 d-flex justify-content-end">
                <a class="btn btn-primary" href="{{ route('custom-fields.index') }}">
                    < {{ __('Back to Custom Fields') }}
                </a>
            </div>
        </div>
    </div>
@endsection

@section('content')
<section class="section">
    <div class="card">
        <div class="card-body">

            <form action="{{ route('custom-fields.bulk-update') }}"
                  class="edit-form"
                  data-success-function="afterBulkCustomFieldsUpdate"
                  method="POST"
                  enctype="multipart/form-data">
                @csrf

                <div class="accordion" id="bulkCustomFieldAccordion">
                    @foreach($customFields as $idx => $cf)
                        @php
                            $t = $translationsByField[$cf->id] ?? [];
                            $sel = $selectedCategoriesByField[$cf->id] ?? [];
                            $selAll = $selectedAllCategoriesByField[$cf->id] ?? [];
                            $valuesTypes = ['radio','dropdown','checkbox'];
                        @endphp

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingCf{{ $cf->id }}">
                                <button class="accordion-button {{ $idx === 0 ? '' : 'collapsed' }}" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#collapseCf{{ $cf->id }}"
                                        aria-expanded="{{ $idx === 0 ? 'true' : 'false' }}">
                                    #{{ $cf->id }} — {{ $cf->name }}
                                </button>
                            </h2>

                            <div id="collapseCf{{ $cf->id }}" class="accordion-collapse collapse {{ $idx === 0 ? 'show' : '' }}"
                                 data-bs-parent="#bulkCustomFieldAccordion">
                                <div class="accordion-body">

                                    <div class="row">
                                        <div class="col-md-6 col-sm-12">
                                            <div class="card">
                                                <div class="card-header">{{ __('Edit Custom Field') }}</div>
                                                <div class="card-body mt-2">

                                                    <ul class="nav nav-tabs" id="langTabsCf{{ $cf->id }}" role="tablist">
                                                        @foreach ($languages as $k => $lang)
                                                            <li class="nav-item" role="presentation">
                                                                <button class="nav-link @if($k===0) active @endif"
                                                                        id="tab-cf-{{ $cf->id }}-{{ $lang->id }}"
                                                                        data-bs-toggle="tab"
                                                                        data-bs-target="#lang-cf-{{ $cf->id }}-{{ $lang->id }}"
                                                                        type="button" role="tab">
                                                                    {{ $lang->name }}
                                                                </button>
                                                            </li>
                                                        @endforeach
                                                    </ul>

                                                    <div class="tab-content mt-3">
                                                        @foreach ($languages as $k => $lang)
                                                            <div class="tab-pane fade @if($k===0) show active @endif"
                                                                 id="lang-cf-{{ $cf->id }}-{{ $lang->id }}" role="tabpanel">

                                                                <div class="form-group mb-3">
                                                                    <label>{{ __('Field Name') }} ({{ $lang->name }})</label>
                                                                    <input type="text"
                                                                           name="fields[{{ $cf->id }}][name][{{ $lang->id }}]"
                                                                           class="form-control"
                                                                           value="{{ $t[$lang->id]['name'] ?? '' }}"
                                                                           @if($lang->id == 1) required @endif>
                                                                </div>

                                                                @if ($lang->id == 1)
                                                                    <div class="form-group mb-3">
                                                                        <label>{{ __('Field Type') }}</label>
                                                                        <select class="form-control" disabled>
                                                                            <option value="{{ $cf->type }}" selected>{{ $cf->type }}</option>
                                                                        </select>
                                                                        <input type="hidden" name="fields[{{ $cf->id }}][type]" value="{{ $cf->type }}">
                                                                        <small class="text-muted">{{ __('Field type cannot be changed after creation.') }}</small>
                                                                    </div>

                                                                    <div class="row">
                                                                        <div class="col-md-6 form-group mb-3">
                                                                            <label>{{ __('Field Length (Min)') }}</label>
                                                                            <input type="number"
                                                                                   name="fields[{{ $cf->id }}][min_length]"
                                                                                   class="form-control"
                                                                                   value="{{ $cf->min_length }}">
                                                                        </div>
                                                                        <div class="col-md-6 form-group mb-3">
                                                                            <label>{{ __('Field Length (Max)') }}</label>
                                                                            <input type="number"
                                                                                   name="fields[{{ $cf->id }}][max_length]"
                                                                                   class="form-control"
                                                                                   value="{{ $cf->max_length }}">
                                                                        </div>
                                                                    </div>

                                                                    <div class="form-group mb-3">
                                                                        <label class="form-label">{{ __('Icon') }}</label>
                                                                        <input type="file"
                                                                               name="fields[{{ $cf->id }}][image]"
                                                                               class="form-control bulk-cf-image"
                                                                               accept=".jpg,.jpeg,.png,.svg">
                                                                        <div class="mt-2">
                                                                            <img class="preview-image img w-25 bulk-cf-preview"
                                                                                 src="{{ empty($cf->image) ? asset('assets/img_placeholder.jpeg') : $cf->image }}"
                                                                                 alt="">
                                                                        </div>
                                                                    </div>

                                                                    <div class="row">
                                                                        <div class="col-md-6 form-group mb-3">
                                                                            <div class="form-check form-switch">
                                                                                <input type="hidden" name="fields[{{ $cf->id }}][required]" value="0">
                                                                                <input class="form-check-input" type="checkbox"
                                                                                       name="fields[{{ $cf->id }}][required]" value="1"
                                                                                       {{ $cf->required ? 'checked' : '' }}>
                                                                                <label class="form-check-label">{{ __('Required') }}</label>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-6 form-group mb-3">
                                                                            <div class="form-check form-switch">
                                                                                <input type="hidden" name="fields[{{ $cf->id }}][status]" value="0">
                                                                                <input class="form-check-input" type="checkbox"
                                                                                       name="fields[{{ $cf->id }}][status]" value="1"
                                                                                       {{ $cf->status ? 'checked' : '' }}>
                                                                                <label class="form-check-label">{{ __('Active') }}</label>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @endif

                                                                {{-- Values (same behavior as normal editor) --}}
                                                                @php
                                                                    $fieldValues = [];
                                                                    if ($lang->id == 1) {
                                                                        $fieldValues = is_array($cf->values) ? $cf->values : [];
                                                                    } else {
                                                                        $fieldValues = (isset($t[$lang->id]['value']) && is_array($t[$lang->id]['value']))
                                                                            ? $t[$lang->id]['value']
                                                                            : [];
                                                                    }
                                                                @endphp

                                                                <div class="form-group mb-3"
                                                                     style="{{ in_array($cf->type, $valuesTypes) ? '' : 'display:none;' }}">
                                                                    <label>{{ __('Field Values') }} ({{ $lang->name }})</label>
                                                                    <select
                                                                        name="fields[{{ $cf->id }}][values][{{ $lang->id }}][]"
                                                                        data-tags="true"
                                                                        data-placeholder="{{ __('Select an option') }}"
                                                                        data-allow-clear="true"
                                                                        data-token-separators="[',']"
                                                                        class="select2 w-100 full-width-select2"
                                                                        multiple="multiple"
                                                                        @if($lang->id==1 && in_array($cf->type, $valuesTypes)) required @endif
                                                                    >
                                                                        @foreach ($fieldValues as $val)
                                                                            <option value="{{ $val }}" selected>{{ $val }}</option>
                                                                        @endforeach
                                                                    </select>
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
                                                    <div class="sub_category_lit bulk-edit-page">
                                                        @foreach ($categories as $category)
                                                            <div class="category">
                                                                <div class="category-header">
                                                                    <label>
                                                                        <input type="checkbox"
                                                                               name="fields[{{ $cf->id }}][selected_categories][]"
                                                                               value="{{ $category->id }}"
                                                                               {{ in_array($category->id, $sel) ? 'checked' : '' }}>
                                                                        {{ $category->name }}
                                                                    </label>

                                                                    @if (!empty($category->subcategories))
                                                                        @php
                                                                            $currentLang = Session::get('language');
                                                                            $isRtl = false;
                                                                            if (!empty($currentLang)) {
                                                                                try {
                                                                                    $rtlRaw = method_exists($currentLang, 'getRawOriginal') ? $currentLang->getRawOriginal('rtl') : null;
                                                                                    if ($rtlRaw !== null) $isRtl = ($rtlRaw == 1 || $rtlRaw === true);
                                                                                    else $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                                                                                } catch (\Exception $e) {
                                                                                    $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                                                                                }
                                                                            }
                                                                            $arrowIcon = $isRtl ? '&#xf0d9;' : '&#xf0da;';
                                                                        @endphp

                                                                        <i style="font-size:24px"
                                                                           class="fas toggle-button {{ in_array($category->id, $selAll) ? 'open' : '' }}">
                                                                            {!! $arrowIcon !!}
                                                                        </i>
                                                                    @endif
                                                                </div>

                                                                <div class="subcategories"
                                                                     style="display: {{ in_array($category->id, $selAll) ? 'block' : 'none' }};">
                                                                    @if (!empty($category->subcategories))
                                                                        @include('category.treeview-bulk', [
                                                                            'categories' => $category->subcategories,
                                                                            'selected_categories' => $sel,
                                                                            'selected_all_categories' => $selAll,
                                                                            'input_name' => "fields[{$cf->id}][selected_categories]"
                                                                        ])
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>{{-- row --}}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="text-end mt-3">
                    <button class="btn btn-primary" type="submit">{{ __('Save All') }}</button>
                </div>
            </form>

        </div>
    </div>
</section>
@endsection

@section('js')
<script>
    function afterBulkCustomFieldsUpdate() {
        setTimeout(function () {
            window.location.href = "{{ route('custom-fields.index') }}";
        }, 700);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // select2 init (same style as normal editor)
        if (window.$ && $.fn.select2) {
            $('select.select2').each(function () {
                if (!$(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2({
                        tags: true,
                        tokenSeparators: [','],
                        placeholder: "{{ __('Select an option') }}",
                        allowClear: true
                    });
                }
            });
        }

        // local toggle for tree
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.bulk-edit-page .toggle-button');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();
            btn.classList.toggle('open');

            const category = btn.closest('.category');
            if (!category) return;
            const sub = category.querySelector(':scope > .subcategories');
            if (!sub) return;
            sub.style.display = (sub.style.display === 'none' || sub.style.display === '') ? 'block' : 'none';
        });

        // image preview per CF
        document.querySelectorAll('.bulk-cf-image').forEach(function (input) {
            input.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (!file) return;
                const wrap = this.closest('.form-group');
                const img = wrap ? wrap.querySelector('.bulk-cf-preview') : null;
                if (img) img.src = URL.createObjectURL(file);
            });
        });
    });
</script>
@endsection
