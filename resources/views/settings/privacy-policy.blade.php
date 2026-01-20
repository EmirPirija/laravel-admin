@extends('layouts.main')
@section('title')
    {{ __('Privacy Policy') }}
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
            <form action="{{ route('settings.store')}}" method="post" class="create-form-without-reset">
                @csrf
                <div class="card-body">
                    <div class="row form-group">
                        <div class="col-2 d-flex justify-content-end">
                            <a href="{{ route('public.privacy-policy') }}" target="_blank" class="col-sm-12 col-md-12 d-fluid btn icon btn-primary btn-sm rounded-pill" onclick="" title="Enable">
                                <i class="bi bi-eye-fill"></i>
                            </a>
                        </div>
                       <ul class="nav nav-tabs" id="privacyTabs" role="tablist">
                    @foreach($languages as $lang)
                        <li class="nav-item" role="presentation">
                            <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                               id="privacy-tab-{{ $lang->id }}"
                               data-bs-toggle="tab"
                               href="#privacy-lang-{{ $lang->id }}"
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
                             id="privacy-lang-{{ $lang->id }}"
                             role="tabpanel">
                            <input type="hidden" name="languages[]" value="{{ $lang->id }}">
                            <div class="form-group">
                                <label>{{ __("Privacy Policy") }} ({{ $lang->name }})</label>
                                <textarea name="privacy_policy[{{ $lang->id }}]"
                                          id="tinymce_editor_privacy_{{ $lang->id }}"
                                          class="tinymce_editor form-control"
                                          rows="6">{{ old("privacy_policy.$lang->id", $translations['privacy_policy'][$lang->id] ?? ($settings['privacy_policy'] ?? '')) }}</textarea>
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
