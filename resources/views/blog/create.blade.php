@extends('layouts.main')
@section('title')
    {{__("Create Blogs")}}
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
            <a class="btn btn-primary" href="{{ route('blog.index') }}">< {{__("Back to Blogs")}} </a>
        </div>
        <div class="row">
            <form action="{{ route('blog.store') }}" class="form-redirection" data-parsley-validate method="POST" enctype="multipart/form-data">
                @csrf
                <div class="card">
                    <div class="card-header">{{__("Add Blog")}}</div>
                    <div class="card-body mt-3">
                        <ul class="nav nav-tabs" id="languageTabs" role="tablist">
                            @foreach($languages as $lang)
                                <li class="nav-item" role="presentation">
                                    <a class="nav-link {{ $loop->first ? 'active' : '' }}" id="tab-{{ $lang->id }}" data-bs-toggle="tab" href="#lang-{{ $lang->id }}" role="tab">
                                        {{ $lang->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        <div class="tab-content mt-3">
                            @foreach($languages as $lang)
                                <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="lang-{{ $lang->id }}" role="tabpanel">
                                    <div class="row">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>{{ __("Title") }} ({{ $lang->name }})</label>
                                                <input type="text" name="title[{{ $lang->id }}]" class="form-control" data-parsley-required="true">
                                            </div>
                                        </div>
                                        @if($lang->id == 1)
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{ __("Slug") }}</label>
                                                    <input type="text" name="slug" class="form-control" data-parsley-required="true">
                                                   
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{ __("Image") }}</label>
                                                    <input type="file" name="image" class="form-control" data-parsley-required="true"  accept=".jpg,.jpeg,.png">
                                                </div>
                                            </div>
                                        @endif
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>{{ __("Tags") }} ({{ $lang->name }})</label>
                                                 <select id="tags[{{ $lang->id }}][]" name="tags[{{ $lang->id }}][]" data-tags="true" data-placeholder="{{__("Tags")}}" data-allow-clear="true" class="select2 col-12 w-100" multiple="multiple" data-parsley-required="true"></select>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>{{ __("Description") }} ({{ $lang->name }})</label>
                                                <textarea name="blog_description[{{ $lang->id }}]" id="tinymce_editor_{{ $lang->id }}" class="tinymce_editor form-control" rows="5"></textarea>
                                            </div>
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
