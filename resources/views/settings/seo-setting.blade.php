@extends('layouts.main')

@section('title')
    {{ __('Seo-settings') }}
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
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <div class="divider">
                            <div class="divider-text">
                                <h4>{{ __('Seo Setting') }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <div class="row form-group">
                                <div class="col-sm-12 col-md-12 form-group">
                                    <form action="{{ route('seo-setting.store') }}" method="POST" enctype="multipart/form-data" data-parsley-validate class="create-form">
                                        @csrf
                                        {{-- <div class="row">
                                            <div class="col-sm-12 col-md-12 form-group mandatory">
                                                <label for="page" class="form-label text-center">{{ __('Page') }}</label>
                                                <select class="form-control" name="page" data-parsley-required="true">
                                                    <option value="">Select Page</option>
                                                    <option value="home">Home</option>
                                                    <option value="subscription">Subscription</option>
                                                    <option value="blogs">Blogs</option>
                                                    <option value="faqs">Faqs</option>
                                                    <option value="ad-listing">Ad Listing</option>
                                                    <option value="about-us">About us</option>
                                                    <option value="contact-us">Contact us</option>
                                                    <option value="landing">Landing</option>
                                                    <option value="privacy-policy">Privacy Policy</option>
                                                    <option value="terms-and-conditions">Terms and Conditions</option>
                                                </select>

                                            </div>

                                            <div class="col-sm-12 col-md-12 form-group mandatory">
                                                <label for="meta_title" class="form-label text-center">{{ __('Title') }}</label>
                                                <input type="text" name="title" class="form-control" id="meta_title" placeholder="{{ __('Title') }}" data-parsley-required="true">
                                                <h6 id="meta_title_count"></h6>
                                            </div>

                                            <div class="col-sm-12 col-md-12 form-group mandatory">
                                                <label for="meta_description" class="form-label text-center">{{ __('Description') }}</label>
                                                <textarea name="description" class="form-control" id="meta_description" placeholder="{{ __('Description') }}" data-parsley-required="true"></textarea>
                                                <h6 id="meta_description_count"></h6>
                                            </div>
                                            <div class="col-sm-12 col-md-12 form-group mandatory">
                                                <label for="keywords" class="form-label text-center">{{ __('Keywords') }}</label>
                                                <textarea name="keywords" class="form-control" placeholder="{{ __('Keywords') }}" data-parsley-required="true"></textarea>
                                            </div>

                                            <div class="col-sm-12 col-md-12 form-group mandatory">
                                                <label for="image" class="form-label">{{ __('Image') }}</label>
                                                <input class="filepond" type="file" name="image" id="favicon_icon">
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-sm-12 d-flex justify-content-end mt-3">
                                                <button type="submit" class="btn btn-primary me-1 mb-1">{{ __('Save') }}</button>
                                            </div>
                                        </div> --}}
                                        <div class="row">
                                            <div class="col-12">
                                                {{-- English-only fields --}}
                                                <div class="form-group mandatory">
                                                    <label for="page" class="form-label">{{ __('Page') }}</label>
                                                    <select class="form-control" name="page" data-parsley-required="true">
                                                        <option value="">{{ __('Select Page') }}</option>
                                                        <option value="home">{{ __('Home') }}</option>
                                                        <option value="subscription">{{ __('Subscription') }}</option>
                                                        <option value="blogs">{{ __('Blogs') }}</option>
                                                        <option value="faqs">{{ __('Faqs') }}</option>
                                                        <option value="ad-listing">{{ __('Ad Listing') }}</option>
                                                        <option value="about-us">{{ __('About us') }}</option>
                                                        <option value="contact-us">{{ __('Contact us') }}</option>
                                                        <option value="landing">{{ __('Landing') }}</option>
                                                        <option value="privacy-policy">{{ __('Privacy Policy') }}</option>
                                                         <option value="refund-policy">{{ __('Refund Policy') }}</option>
                                                        <option value="terms-and-conditions">{{ __('Terms and Conditions') }}</option>
                                                    </select>
                                                </div>

                                                <div class="form-group mandatory">
                                                    <label for="image" class="form-label">{{ __('Image') }}</label>
                                                    <input class="filepond" type="file" name="image" id="favicon_icon">
                                                </div>

                                                {{-- Language Tabs --}}
                                                <ul class="nav nav-tabs mt-3" id="languageTabs" role="tablist">
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
                                                            <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                                            <div class="form-group">
                                                                <label>{{ __("Title") }} ({{ $lang->name }})</label>
                                                                <input type="text" name="title[{{ $lang->id }}]" class="form-control">
                                                            </div>

                                                            <div class="form-group">
                                                                <label>{{ __("Description") }} ({{ $lang->name }})</label>
                                                                <textarea name="description[{{ $lang->id }}]" class="form-control" rows="3"></textarea>
                                                            </div>

                                                            <div class="form-group">
                                                                <label>{{ __("Keywords") }} ({{ $lang->name }})</label>
                                                                <textarea name="keywords[{{ $lang->id }}]" class="form-control" rows="2"></textarea>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-sm-12 d-flex justify-content-end mt-3">
                                                <button type="submit" class="btn btn-primary me-1 mb-1">{{ __('Save') }}</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                <table class="table-light table-striped" aria-describedby="mydesc" id="table_list" data-toggle="table" data-url="{{ route('seo-setting.show',1) }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false" data-responsive="true" data-sort-name="id" data-sort-order="desc" data-pagination-successively-size="3" data-escape="true" data-query-params="queryParams" data-mobile-responsive="true">
                                    <thead>
                                    <tr>
                                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                        <th scope="col" data-field="page" data-sortable="false">{{ __('Page') }}</th>
                                        <th scope="col" data-field="title" data-sortable="false">{{ __('Title')}}</th>
                                        <th scope="col" data-field="description" data-sortable="true">{{ __('Description') }}</th>
                                        <th scope="col" data-field="keywords" data-sortable="true">{{ __('Keywords') }}
                                        <th scope="col" data-field="image" data-sortable="false" data-formatter="imageFormatter">{{ __('Image') }}
                                        <th scope="col" data-field="operate" data-escape="false" data-sortable="false" data-events="SeoSettingEvents">{{ __('Action') }}</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- EDIT MODEL MODEL -->
    <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="#" class="form-horizontal" id="edit-form" enctype="multipart/form-data" method="POST" data-parsley-validate>
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Edit Seo Setting') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="col-md-12 col-12">
                                    <div class="form-group mandatory">
                                        <label for="edit_page" class="form-label col-12">{{ __('Page') }}</label>
                                        <input type="text" id="edit_page" class="form-control col-12" placeholder="{{__("Page")}}" name="page" data-parsley-required="true" disabled>
                                    </div>
                                </div>
                            </div>
                             <div class="col-sm-12 col-md-12 form-group">
                                <label class="col-form-label ">{{ __('Image') }}</label>
                                <div class="">
                                    <input class="filepond" type="file" name="image" id="edit_image">
                                </div>
                            </div>
                            <ul class="nav nav-tabs mt-3" role="tablist">
                                @foreach($languages as $lang)
                                    <li class="nav-item" role="presentation">
                                        <a class="nav-link {{ $loop->first ? 'active' : '' }}" id="edit-tab-{{ $lang->id }}" data-bs-toggle="tab" href="#edit-lang-{{ $lang->id }}" role="tab">
                                            {{ $lang->name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach($languages as $lang)
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="edit-lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __("Title") }} ({{ $lang->name }})</label>
                                            <input type="text" name="title[{{ $lang->id }}]" id="edit_title_{{ $lang->id }}" class="form-control">
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __("Description") }} ({{ $lang->name }})</label>
                                            <textarea name="description[{{ $lang->id }}]" id="edit_description_{{ $lang->id }}" class="form-control" rows="3"></textarea>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __("Keywords") }} ({{ $lang->name }})</label>
                                            <textarea name="keywords[{{ $lang->id }}]" id="edit_keywords_{{ $lang->id }}" class="form-control" rows="2"></textarea>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                        <button type="submit" class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
@section('script')
<script>
    const maxPixelWidth = 400;      // Adjust as needed
    const tooLongPixelWidth = 600;  // Adjust as needed

    // Reusable width calculator
    function getTextWidth(text, font) {
        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        context.font = font;
        return context.measureText(text).width;
    }

    function updateMetaLength(inputId, maxPixelWidth, tooLongPixelWidth) {
        const input = $(`#${inputId}`);
        const countElement = $(`#${inputId}_count`);

        if (input.length && countElement.length) {
            const text = input.val().trim();
            let textPixelLength = Math.round(getTextWidth(text, '19.9px Arial'));

            let iconClass = 'fa-exclamation-triangle text-danger';
            let feedbackMessage = `Your Meta is too short.`;
            let feedbackColor = 'text-danger';

            if (textPixelLength >= maxPixelWidth && textPixelLength <= tooLongPixelWidth) {
                iconClass = 'fa-check-circle text-success';
                feedbackMessage = `Your Meta is an acceptable length.`;
                feedbackColor = 'text-success';
            } else if (textPixelLength > tooLongPixelWidth) {
                feedbackMessage = `Meta should be around ${tooLongPixelWidth}px in length.`;
            }

            countElement.html(`
                <i class="fa ${iconClass}"></i>
                <span><b>${textPixelLength}</b> pixels</span>
                <span class="${feedbackColor}"> -- ${feedbackMessage}</span>
            `);
        }
    }

    // Trigger check on input for each language
    $(document).ready(function () {
        @foreach ($languages as $lang)
            // Title
            $(`#meta_title_{{ $lang->id }}`).on('input', function () {
                updateMetaLength('meta_title_{{ $lang->id }}', maxPixelWidth, tooLongPixelWidth);
            });
            $(`#meta_description_{{ $lang->id }}`).on('input', function () {
                updateMetaLength('meta_description_{{ $lang->id }}', maxPixelWidth, tooLongPixelWidth);
            });

            $(`#meta_keywords_{{ $lang->id }}`).on('input', function () {
                updateMetaLength('meta_keywords_{{ $lang->id }}', maxPixelWidth, tooLongPixelWidth);
            });
        @endforeach
    });
</script>
@endsection
