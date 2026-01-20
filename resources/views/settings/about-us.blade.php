@extends('layouts.main')

@section('title')
    {{ __('About Us') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">

            </div>
        </div>
    </div>
@endsection
@section('content')
    <section class="section">
        {{-- <div class="card">
            <form action="{{ route('settings.store') }}" method="post" class="create-form-without-reset">
                @csrf
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12">
                            <textarea id="tinymce_editor" name="about_us" class="form-control col-md-7 col-xs-12" aria-label="tinymce_editor">{{ $settings["about_us"] ??'' }}</textarea>
                        </div>

                         @if($languages_translate->isNotEmpty())
                            <div class="col-md-12 mt-3">
                                <hr>
                                <h5>{{ __("Translations") . " (" . __("Optional") . ")" }}</h5>
                            </div>

                            @foreach($languages_translate as $language)
                                <div class="col-md-12 mb-4 border p-3 rounded shadow-sm">
                                    <h6 class="mb-3 text-primary">
                                        {{ __("Translation for") }}: <strong>{{ $language->name }} ({{ $language->code }})</strong>
                                    </h6>

                                    <input type="hidden" name="translations[{{ $language->id }}][name]" value="about_us">

                                    <div class="form-group">
                                        <label for="translation_{{ $language->id }}" class="form-label">
                                            {{ __("Translated About Us") }}
                                        </label>
                                        <textarea class="form-control"
                                                  name="translations[{{ $language->id }}][value]"
                                                  rows="4"
                                                  placeholder="{{ __('About Us in') . ' ' . $language->name }}">
                                            {{ old("translations.{$language->id}.value", $translations['about_us'][$language->id] ?? '') }}
                                        </textarea>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                    <div class="col-12 mt-2 d-flex justify-content-end">
                        <button class="btn btn-primary me-1 mb-1" type="submit" name="submit">{{ __('Save') }}</button>
                    </div>
                </div>
            </form>
        </div> --}}
        <div class="card">
    <form action="{{ route('settings.store') }}" method="post" class="create-form-without-reset">
        @csrf
        <div class="card-body">
            <ul class="nav nav-tabs" id="languageTabs" role="tablist">
                @foreach($languages as $lang)
                    <li class="nav-item" role="presentation">
                        <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                           id="tab-{{ $lang->id }}"
                           data-bs-toggle="tab"
                           href="#lang-{{ $lang->id }}"
                           role="tab">
                            {{ $lang->name }}
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content mt-3">
                @foreach($languages as $lang)
                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                         id="lang-{{ $lang->id }}"
                         role="tabpanel">
                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">
                        <div class="form-group">
                            <label>{{ __("About Us") }} ({{ $lang->name }})</label>
                            <textarea name="about_us[{{ $lang->id }}]"
                                      id="tinymce_editor_{{ $lang->id }}"
                                      class="tinymce_editor form-control"
                                      rows="6">{{ old("about_us.$lang->id", $translations['about_us'][$lang->id] ?? ($settings['about_us'] ?? '')) }}</textarea>
                        </div>
                    </div>
                @endforeach
            </div>

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
