@extends('layouts.main')

@section('title')
    {{ __('Bulk Edit Categories') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 d-flex justify-content-end">
                <a class="btn btn-primary" href="{{ route('category.index') }}">
                    < {{ __('Back to Categories') }}
                </a>
            </div>
        </div>
    </div>
@endsection

@section('content')
<section class="section">
    <div class="card">
        <div class="card-body">
            <form action="{{ route('category.bulk-update') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="accordion" id="bulkCategoryAccordion">
                    @foreach($categories as $idx => $cat)
                        @php
                            $t = $translationsByCategory[$cat->id] ?? [];
                        @endphp

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingCat{{ $cat->id }}">
                                <button class="accordion-button {{ $idx === 0 ? '' : 'collapsed' }}" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#collapseCat{{ $cat->id }}"
                                        aria-expanded="{{ $idx === 0 ? 'true' : 'false' }}">
                                    #{{ $cat->id }} — {{ $cat->name }}
                                </button>
                            </h2>

                            <div id="collapseCat{{ $cat->id }}" class="accordion-collapse collapse {{ $idx === 0 ? 'show' : '' }}"
                                 data-bs-parent="#bulkCategoryAccordion">
                                <div class="accordion-body">
                                    {{-- Language tabs --}}
                                    <ul class="nav nav-tabs" id="langTabsCat{{ $cat->id }}" role="tablist">
                                        @foreach ($languages as $k => $lang)
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link @if($k === 0) active @endif"
                                                        id="tab-cat-{{ $cat->id }}-{{ $lang->id }}"
                                                        data-bs-toggle="tab"
                                                        data-bs-target="#lang-cat-{{ $cat->id }}-{{ $lang->id }}"
                                                        type="button" role="tab">
                                                    {{ $lang->name }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="tab-content mt-3">
                                        @foreach ($languages as $k => $lang)
                                            <div class="tab-pane fade @if($k === 0) show active @endif"
                                                 id="lang-cat-{{ $cat->id }}-{{ $lang->id }}" role="tabpanel">

                                                <div class="form-group mb-3">
                                                    <label>{{ __('Name') }} ({{ $lang->name }})</label>
                                                    <input type="text"
                                                           name="categories[{{ $cat->id }}][name][{{ $lang->id }}]"
                                                           class="form-control"
                                                           value="{{ $t[$lang->id]['name'] ?? '' }}"
                                                           @if($lang->id == 1) required @endif>
                                                </div>

                                                <div class="form-group mb-3">
                                                    <label>{{ __('Description') }} ({{ $lang->name }})</label>
                                                    <textarea
                                                        name="categories[{{ $cat->id }}][description][{{ $lang->id }}]"
                                                        class="form-control"
                                                        rows="3">{{ $t[$lang->id]['description'] ?? '' }}</textarea>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label>{{ __('Parent Category') }}</label>
                                            <select class="form-control"
                                                    name="categories[{{ $cat->id }}][parent_category_id]">
                                                <option value="">{{ __('None') }}</option>
                                                @foreach($allCategories as $opt)
                                                    @if($opt->id != $cat->id)
                                                        <option value="{{ $opt->id }}"
                                                            {{ (int)$cat->parent_category_id === (int)$opt->id ? 'selected' : '' }}>
                                                            {{ $opt->full_path ?? $opt->name }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label>{{ __('Slug') }}</label>
                                            <input type="text"
                                                   class="form-control"
                                                   name="categories[{{ $cat->id }}][slug]"
                                                   value="{{ $cat->slug ?? '' }}"
                                                   placeholder="letters/numbers/-/_ only">
                                        </div>
                                    </div>

                                    <div class="row align-items-center">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">{{ __('Image') }}</label>
                                            <input type="file"
                                                   class="form-control bulk-cat-image"
                                                   name="categories[{{ $cat->id }}][image]"
                                                   accept=".jpg,.jpeg,.png">
                                            <div class="mt-2">
                                                <img class="img w-25 bulk-cat-preview"
                                                     src="{{ empty($cat->image) ? asset('assets/img_placeholder.jpeg') : $cat->image }}"
                                                     alt="">
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch mb-2">
                                                <input type="hidden" name="categories[{{ $cat->id }}][status]" value="0">
                                                <input class="form-check-input" type="checkbox"
                                                       name="categories[{{ $cat->id }}][status]" value="1"
                                                       {{ $cat->status ? 'checked' : '' }}>
                                                <label class="form-check-label">{{ __('Active') }}</label>
                                            </div>

                                            <div class="form-check form-switch mb-2">
                                                <input type="hidden" name="categories[{{ $cat->id }}][is_job_category]" value="0">
                                                <input class="form-check-input" type="checkbox"
                                                       name="categories[{{ $cat->id }}][is_job_category]" value="1"
                                                       {{ $cat->is_job_category ? 'checked' : '' }}>
                                                <label class="form-check-label">{{ __('Is Job Category') }}</label>
                                            </div>

                                            <div class="form-check form-switch">
                                                <input type="hidden" name="categories[{{ $cat->id }}][price_optional]" value="0">
                                                <input class="form-check-input" type="checkbox"
                                                       name="categories[{{ $cat->id }}][price_optional]" value="1"
                                                       {{ $cat->price_optional ? 'checked' : '' }}>
                                                <label class="form-check-label">{{ __('Price Optional') }}</label>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="text-end mt-3">
                    <button class="btn btn-primary" type="submit">
                        {{ __('Save All') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection

@section('js')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bulk-cat-image').forEach(function (input) {
            input.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (!file) return;
                const preview = this.closest('.col-md-6').querySelector('.bulk-cat-preview');
                if (preview) preview.src = URL.createObjectURL(file);
            });
        });
    });
</script>
@endsection
