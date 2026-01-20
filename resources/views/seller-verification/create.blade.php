@extends('layouts.main')

@section('title')
    {{__("Seller Verifications")}}
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
        <form action="{{ route('seller-verification.store') }}" method="POST" class="create-form" data-success-function="afterCustomFieldCreation" data-parsley-validate enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header">{{__("Create Seller Verification")}}</div>
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
                                                {{-- Show type only in English --}}
                                                <div class="form-group">
                                                    <label>{{ __('Field Type') }}</label>
                                                    <select name="type" class="form-control" required>
                                                        <option value="number">{{__("Number Input")}}</option>
                                                        <option value="textbox">{{__("Text Input")}}</option>
                                                        <option value="fileinput">{{__("File Input")}}</option>
                                                        <option value="radio">{{__("Radio")}}</option>
                                                        <option value="dropdown">{{__("Dropdown")}}</option>
                                                        <option value="checkbox">{{__("Checkboxes")}}</option>
                                                    </select>
                                                </div>

                                                {{-- Min/Max Fields --}}
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

                                            {{-- Field Values (only for dropdown, radio, checkbox) --}}
                                            <div class="form-group">
                                                <label>{{ __('Field Values') }} ({{ $lang->name }})</label>
                                                <select name="values[{{ $lang->id }}][]" data-tags="true" data-placeholder="{{__("Select an option")}}" data-allow-clear="true" class="select2 w-100 full-width-select2" multiple="multiple" @if($lang->id == 1) required @endif></select>
                                                @if($lang->id != 1)
                                                    <small class="text-muted">{{ __('This will be used for translatable field types only.') }}</small>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            <div class="row">
                                <div class="col-md-6 form-group mandatory">
                                    <div class="form-check form-switch  ">
                                        <input type="hidden" name="is_required" id="required" value="0">
                                        <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="required">{{ __('Required') }}
                                        <label class="form-check-label" for="required"></label>
                                    </div>
                                </div>
                                <div class="col-md-6 form-group mandatory">
                                    <div class="form-check form-switch  ">
                                        <input type="hidden" name="status" id="status" value="1">
                                        <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="status" checked>{{ __('Active') }}
                                        <label class="form-check-label" for="status"></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-12">
                    <input type="submit" class="btn btn-primary" value="{{__("Save and Back")}}">
                </div>
            </div>
        </form>
    </section>
@endsection
@section('script')
    <script>
       function updateVerificationFieldUI() {
        const type = $('select[name="type"]').val();
        const valuesTypes = ['radio', 'dropdown', 'checkbox'];

        // Loop through each language tab
        $('.tab-pane').each(function () {
            const $tab = $(this);
            const langId = $tab.attr('id').replace('lang-', '');

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
        updateVerificationFieldUI(); // Run on load

        $(document).on('change', 'select[name="type"]', function () {
            updateVerificationFieldUI(); // Run on change
        });
    });

        function afterCustomFieldCreation() {
            setTimeout(function () {
                window.location.href = "{{route('seller-verification.verification-field')}}";
            }, 1000)
        }
    </script>
@endsection
