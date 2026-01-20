@extends('layouts.main')
@section('title')
    {{__("Edit Blogs")}}
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
            <a class="btn btn-primary" href="{{ route('blog.index') }}">< {{__("Back to All Blogs")}} </a>
        </div>
        <div class="row">
            <form action="{{ route('blog.update', $blog->id) }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @method('PUT')
                @csrf
                <input type="hidden" name="edit_data" value={{ $blog->id }}>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{__("Edit Blogs")}}</div>
                        <div class="card-body mt-3">
                            {{-- <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="form-group mandatory">
                                        <label for="title" class="mandatory form-label">{{ __('Title') }}</label>
                                        <input type="text" name="title" id="title" class="form-control" data-parsley-required="true" value="{{ $blog->title }}">
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="form-group mandatory">
                                        <label for="slug" class="form-label">{{ __('Slug') }} <small>{{__('(English Only)')}}</small></label>
                                        <input type="text" name="slug" id="slug" class="form-control" data-parsley-required="true" value="{{$blog->slug}}">
                                    </div>
                                </div>

                                <div class="col-md-6 col-12">
                                    <div class="form-group mandatory">
                                        <label for="Field Name" class="mandatory form-label">{{ __('Image') }}</label>
                                        <div class="cs_field_img ">
                                            <input type="file" name="image" class="image" style="display: none" accept=" .jpg, .jpeg, .png, .svg">
                                            <img src="{{ empty($blog->image) ? asset('assets/img_placeholder.jpeg') : $blog->image }}" alt="" class="img preview-image" id="">
                                            <div class='img_input'>{{__("Browse File")}}</div>
                                        </div>
                                        <div class="input_hint"> {{__("Icon (use 256 x 256 size for better view)")}}</div>
                                        <div class="img_error" style="color:#DC3545;"></div>
                                    </div>
                                </div>
                                <div class="col-md-12 col-12">
                                    <div class="form-group mandatory">
                                        <label for="tags" class="mandatory form-label">{{ __('Tags') }}</label>
                                        <select id="tags" name="tags[]" data-tags="true" data-placeholder="{{__("Tags")}}" data-allow-clear="true" class="select2 col-12 w-100" multiple="multiple" data-parsley-required="true">
                                            @foreach ($blog->tags as $tag)
                                                <option value="{{ $tag }}" selected>{{ $tag }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-12 col-12">
                                    <label for="tinymce_editor" class="mandatory form-label">{{ __('Description') }}</label>
                                    <textarea name="blog_description" id="tinymce_editor" class="form-control" cols="10" rows="4">{{ $blog->description }}</textarea>
                                </div>

                            </div> --}}
                            <ul class="nav nav-tabs" id="languageTabs" role="tablist">
                                @foreach($languages as $lang)
                                    <li class="nav-item" role="presentation">
                                        <a class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" href="#lang-{{ $lang->id }}">
                                            {{ $lang->name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach($languages as $lang)
                                    @php
                                        $isEnglish = $lang->id == 1;
                                        $trans = isset($translations) ? ($translations[$lang->id] ?? null) : null;
                                    @endphp
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="lang-{{ $lang->id }}">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="row">
                                            <div class="col-md-6">
                                                <label>{{ __("Title") }} ({{ $lang->name }})</label>
                                                <input type="text" name="title[{{ $lang->id }}]" class="form-control"
                                                    value="{{  $isEnglish ? ($blog->title ?? '') : ($trans->title ?? '') }}">
                                            </div>

                                            <div class="col-md-6">
                                                <label>{{ __("Slug") }}</label>
                                                <input type="text" name="slug" class="form-control"
                                                    value="{{ $isEnglish ? ($blog->slug ?? '') : '' }}" {{ !$isEnglish ? 'disabled' : '' }}>
                                                @if(!$isEnglish)
                                                    <small class="text-danger">{{ __("This field can be added in English only.") }}</small>
                                                @endif
                                            </div>

                                            <div class="col-md-6">
                                                <label>{{ __("Image") }}</label>
                                                @if($isEnglish && isset($blog))
                                                    <img src="{{ $blog->image }}" alt="Image" style="max-height: 100px;" class="mb-2 d-block">
                                                @endif
                                                <input type="file" name="image" class="form-control" {{ !$isEnglish ? 'disabled' : '' }} accept=".jpg,.jpeg,.png">
                                                @if(!$isEnglish)
                                                    <small class="text-danger">{{ __("This field can be added in English only.") }}</small>
                                                @endif
                                            </div>

                                            <div class="col-md-6">
                                                <label>{{ __("Tags") }}</label>
                                                <select name="tags[{{ $lang->id }}][]" data-tags="true" data-placeholder="{{__("Tags")}}" data-allow-clear="true"
                                                    class="select2 col-12 w-100" multiple="multiple">
                                                   @php
                                                        $selectedTags = old("tags.$lang->id", $isEnglish ? ($blog->tags ?? []) : ($trans->tags ?? []));
                                                    @endphp

                                                   @foreach($selectedTags as $tag)
                                                        <option selected value="{{ $tag }}">{{ $tag }}</option>
                                                    @endforeach

                                                </select>
                                            </div>

                                            <div class="col-md-12">
                                                <label>{{ __("Description") }}</label>
                                                <textarea name="blog_description[{{ $lang->id }}]" id="tinymce_editor_{{ $lang->id }}" class="tinymce_editor form-control" rows="5">{{ old("description.$lang->id", $isEnglish ? ($blog->description ?? '') : ($trans->description ?? '')) }}</textarea>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        </div>
                    </div>
                    <div class="col-md-12 text-end">
                        <input type="submit" class="btn btn-primary" value="{{__("Save and Back")}}">
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
@section('js')
    <script !src="">
        $('#category_id').val("{{$blog->category_id}}")
    </script>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        tinymce.init({
            selector: '.tinymce_editor',
            height: 400,
            menubar: false,
            plugins: [
                'advlist autolink lists link charmap print preview anchor',
                'searchreplace visualblocks code fullscreen',
                'insertdatetime table paste code help wordcount'
            ],
            toolbar: 'undo redo | formatselect | bold italic backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | removeformat | code',
            setup: function (editor) {
                editor.on("change keyup", function () {
                    editor.save(); // Ensure textarea is updated
                });
            }
        });

        // If using Bootstrap 5 tabs, re-init TinyMCE when tab is shown (optional)
        const tabs = document.querySelectorAll('a[data-bs-toggle="tab"]');
        tabs.forEach(tab => {
            tab.addEventListener('shown.bs.tab', () => {
                tinymce.execCommand('mceRemoveEditor', false, null);
                tinymce.init({
                    selector: '.tinymce_editor',
                    height: 400,
                    menubar: false,
                    plugins: 'lists link image table code',
                    toolbar: 'undo redo | bold italic | alignleft aligncenter alignright | bullist numlist | code'
                });
            });
        });
    });
</script>
@endsection
