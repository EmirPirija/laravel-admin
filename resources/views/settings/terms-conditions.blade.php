@extends('layouts.main')
@section('title')
    {{ __('Terms & Conditions') }}
@endsection

@section('page-title')
<div class="page-title">
    <div class="row">
        <div class="col-12 col-md-6 order-md-1 order-last">
            <h4>@yield('title')</h4>
        </div>
        <div class="col-12 col-md-6 order-md-2 order-first"></div>
    </div>
</div>
@endsection

@section('content')
<section class="section">
    <div class="card">
        <form action="{{ route('settings.store') }}" method="post" class="create-form-without-reset">
            @csrf
            <div class="card-body">

                <!-- Preview Button -->
                <div class="row form-group">
                    <div class="col-2 d-flex justify-content-end">
                        <a href="{{ route('public.terms-conditions') }}" target="_blank"
                           class="btn icon btn-primary btn-sm rounded-pill"
                           title="{{ __('View Page') }}">
                            <i class="bi bi-eye-fill"></i>
                        </a>
                    </div>
                </div>

                <!-- Language Tabs -->
                <ul class="nav nav-tabs" id="termsTabs" role="tablist">
                    @foreach($languages as $lang)
                        <li class="nav-item" role="presentation">
                            <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                               id="terms-tab-{{ $lang->id }}"
                               data-bs-toggle="tab"
                               href="#terms-lang-{{ $lang->id }}"
                               role="tab">
                                {{ $lang->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <!-- Tab Panes -->
                <div class="tab-content mt-3">
                    @foreach($languages as $lang)
                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                             id="terms-lang-{{ $lang->id }}"
                             role="tabpanel">
                            <input type="hidden" name="languages[]" value="{{ $lang->id }}">
                            <div class="form-group">
                                <label>{{ __("Terms & Conditions") }} ({{ $lang->name }})</label>
                                <textarea name="terms_conditions[{{ $lang->id }}]"
                                          id="tinymce_editor_terms_{{ $lang->id }}"
                                          class="tinymce_editor form-control"
                                          rows="6">{{ old("terms_conditions.$lang->id", $translations['terms_conditions'][$lang->id] ?? ($settings['terms_conditions'] ?? '')) }}</textarea>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Save Button -->
                <div class="col-12 mt-3 d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                </div>

            </div>
        </form>
    </div>
</section>
@endsection

@section('script')
<script>
document.addEventListener("DOMContentLoaded", () => {
    tinymce.init({
        selector: '.tinymce_editor',
        height: 400,
        menubar: false,
        plugins: [
            'advlist autolink lists link charmap preview anchor',
            'searchreplace visualblocks code fullscreen',
            'insertdatetime table paste code help wordcount'
        ],
        toolbar: 'undo redo | formatselect | bold italic backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | removeformat | code',
        setup: function (editor) {
            editor.on("change keyup", function () {
                editor.save();
            });
        }
    });
});
</script>
@endsection
