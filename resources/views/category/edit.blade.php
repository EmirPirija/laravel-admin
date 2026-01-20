@extends('layouts.main')
@section('title')
    {{__("Edit Categories")}}
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
            <a class="btn btn-primary" href="{{ route('category.index') }}">< {{__("Back to All Categories")}} </a>
        </div>
        <div class="row">
            <form action="{{ route('category.update', $category_data->id) }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @method('PUT')
                @csrf
                <input type="hidden" name="edit_data" value={{ $category_data->id }}>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{__("Edit Categories")}}</div>
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
                                            <label>{{ __('Name') }} ({{ $lang->name }})</label>
                                            <input type="text" 
                                                name="name[{{ $lang->id }}]" 
                                                class="form-control" 
                                                value="{{ $translations[$lang->id]['name'] ?? '' }}"
                                                data-parsley-maxlength="30"
                                                maxlength="30"
                                                data-parsley-maxlength-message="{{ __('Name cannot exceed 30 characters.') }}"
                                                @if($lang->id == 1) data-parsley-required="true" @endif>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Description') }} ({{ $lang->name }})</label>
                                            <textarea name="description[{{ $lang->id }}]" class="form-control" cols="10" rows="5">{{ $translations[$lang->id]['description'] ?? '' }}</textarea>
                                        </div>

                                        @if($lang->id == 1)
                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <div class="col-md-12 form-group mandatory">
                                                        <label for="category_slug" class="form-label">{{ __('Slug') }} <small>{{__('(English Only)')}}</small></label>
                                                        <input type="text" name="slug" id="category_slug" class="form-control" data-parsley-pattern="^[a-zA-Z0-9\-_]+$"
                                                            data-parsley-pattern-message="{{ __('Slug must be only English letters, numbers, hyphens (-) or underscores (_).') }}" value="{{ $category_data->slug }}">
                                                        <label>
                                                            <small class="text-danger">{{ __('Note: Slug must be in English letters, numbers, hyphens (-) or underscores (_). No spaces or special characters.') }}</small>
                                                        </label>
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="col-md-12 form-group mandatory">
                                                        <label for="p_category" class="mandatory form-label">{{ __('Parent Category') }}</label>
                                                        <select name="parent_category_id" class="form-select form-control select2" id="p_category" data-placeholder="{{ __('Select Category') }}">
                                                            @if(isset($parent_category_data) && $parent_category_data->id)
                                                                <option value="{{ $parent_category_data->id }}" id="default_opt" selected>
                                                                    {{ $parent_category == '' ? 'Root' : $parent_category }}
                                                                </option>
                                                            @else
                                                                <option value="">{{ __('Select Category') }}</option>
                                                            @endif
                                                            @include('category.dropdowntree', ['categories' => $categories])
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="col-md-12 form-group mandatory">
                                                        <label for="Field Name" class="mandatory form-label">{{ __('Image') }}</label>
                                                        <div class="cs_field_img">
                                                            <input type="file" name="image" class="image" style="display: none" accept=" .jpg, .jpeg, .png, .svg">
                                                            <img src="{{ empty($category_data->image) ? asset('assets/img_placeholder.jpeg') : $category_data->image }}" alt="" class="img preview-image" id="">
                                                            <div class='img_input'>{{__("Browse File")}}</div>
                                                        </div>
                                                        <div class="input_hint"> {{__("Icon (use 256 x 256 size for better view)")}}</div>
                                                        <div class="img_error" style="color:#DC3545;"></div>
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="row mt-3">
                                                        <div class="col-md-4">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="status" id="status" value="{{ $category_data->status }}">
                                                                <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="status" {{ $category_data->status == 1 ? 'checked' : '' }}>
                                                                <label class="form-check-label" for="status">{{ __('Active') }}</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="is_job_category" value="0">
                                                                <input
                                                                    class="form-check-input"
                                                                    type="checkbox"
                                                                    role="switch"
                                                                    name="is_job_category"
                                                                    id="job_category_switch"
                                                                    value="1"
                                                                    {{ $category_data->is_job_category == 1 ? 'checked' : '' }}
                                                                >
                                                                <label class="form-check-label" for="job_category_switch">{{ __('Job Category') }}</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="price_optional" value="0">
                                                                <input
                                                                    class="form-check-input"
                                                                    type="checkbox"
                                                                    role="switch"
                                                                    name="price_optional"
                                                                    id="price_optional_switch"
                                                                    value="1"
                                                                    {{ $category_data->price_optional == 1 ? 'checked' : '' }}
                                                                >
                                                                <label class="form-check-label" for="price_optional_switch">{{ __('Price Optional') }}</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
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