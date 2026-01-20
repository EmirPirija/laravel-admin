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
        <div class="buttons">
            <a class="btn btn-primary" href="{{ route('seller-verification.verification-field') }}">< {{__("Back to Verification Fields")}} </a>
            @if(in_array($verification_field->type,['radio','checkbox','dropdown']))
                <a class="btn btn-primary" data-bs-toggle="modal" data-bs-target='#addModal'>+ {{__("Add Options")}}</a>
            @endif
        </div>
        <div class="row">
            <form action="{{ route('seller-verification.update', $verification_field->id) }}" method="POST" data-success-function="afterCustomFieldCreation" enctype="multipart/form-data" class="edit-form">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="col-md-6 col-sm-12">
                        <div class="card">
                            <div class="card-header">{{__("Verification Field")}}</div>
                            <div class="card-body mt-3">
                                    {{-- <div class="col-md-12 form-group mandatory">
                                        <label for="name" class="mandatory form-label">{{ __('Field Name') }}</label>
                                        <input type="text" name="name" id="name" class="form-control" data-parsley-required="true" value="{{ $verification_field->name }}">
                                    </div>

                                    <div class="col-md-12 form-group mandatory">
                                        <label for="type" class="mandatory form-label">{{ __('Field Type') }}</label>
                                        <select name="type" id="type" class="form-select form-control">
                                            <option value="{{ $verification_field->type }}" selected>{{ ucfirst($verification_field->type) }}</option>
                                        </select>
                                    </div>

                                    @if(in_array($verification_field->type,['radio','checkbox','dropdown']))
                                        <div class="col-md-12">
                                            <label for="values" class="form-label">{{ __('Field Values') }}</label>
                                            <div class="form-group">
                                                <select id="values" name="values[]" data-tags="true" data-placeholder="{{__("Select an option")}}" data-allow-clear="true" class="select2 col-12 w-100" multiple="multiple">
                                                    @foreach ($verification_field->values as $value)
                                                        <option value="{{ $value }}" selected>{{ $value }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="input_hint">{{__("This will be applied only for")}}:
                                                    <text class="highlighted_text">{{__("Checkboxes").",".__("Radio")}}</text>
                                                    and
                                                    <text class="highlighted_text"> {{__("Dropdown")}}</text>
                                                    .
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if(in_array($verification_field->type,['textbox','fileinput','number']))
                                        <div class="col-md-6 form-group ">
                                            <label for="min_length" class=" form-label">{{ __('Field Length (Min)') }}</label>
                                            <input type="text" name="min_length" id="min_length" class="form-control" value="{{ $verification_field->min_length }}">
                                            <div class="input_hint">  {{__("This will be applied only for")}}:
                                                <text class="highlighted_text">{{__("text").",".__("number")}}</text>
                                                {{__("and")}}
                                                <text class="highlighted_text"> {{__("textarea")}}</text>
                                                .
                                            </div>
                                        </div>
                                        <div class="col-md-6 form-group ">
                                            <label for="max_length" class=" form-label">{{ __('Field Length (Max)') }}</label>
                                            <input type="text" name="max_length" id="max_length" class="form-control" value="{{ $verification_field->max_length }}">
                                            <div class="input_hint"> {{__("This will be applied only for")}}:
                                                <text class="highlighted_text">{{__("text").",".__("number")}}</text>
                                                {{__("and")}}
                                                <text class="highlighted_text"> {{__("textarea")}}</text>
                                            </div>
                                        </div>
                                    @endif --}}
                                    <ul class="nav nav-tabs" role="tablist">
                                            @foreach($languages as $key => $lang)
                                                <li class="nav-item">
                                                    <a class="nav-link @if($key == 0) active @endif"
                                                    data-bs-toggle="tab"
                                                    href="#lang-{{ $lang->id }}">{{ $lang->name }}</a>
                                                </li>
                                            @endforeach
                                        </ul>

                                        <div class="tab-content mt-3">
                                            @foreach($languages as $key => $lang)
                                                @php
                                                    $translated = $verification_field->translations->firstWhere('language_id', $lang->id);
                                                    $isEnglish = $lang->id == 1;
                                                @endphp
                                                <div class="tab-pane fade @if($key == 0) show active @endif" id="lang-{{ $lang->id }}" role="tabpanel">
                                                    <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                                    {{-- Field Name --}}
                                                    <div class="form-group mb-3">
                                                        <label>{{ __('Field Name') }} ({{ $lang->name }})</label>
                                                        <input type="text"
                                                            name="name[{{ $lang->id }}]"
                                                            class="form-control"
                                                            value="{{ $isEnglish ? $verification_field->name : ($translated->name ?? '') }}"
                                                            @if($isEnglish) required @endif>
                                                    </div>

                                                    @if($isEnglish)
                                                        {{-- Field Type --}}
                                                        <div class="form-group mb-3">
                                                            <label>{{ __('Field Type') }}</label>
                                                            <select name="type" id="type" class="form-control" required>
                                                                 <option value="{{ $verification_field->type }}" selected>{{ ucfirst($verification_field->type) }}</option>
                                                            </select>
                                                        </div>

                                                        {{-- Min/Max fields --}}
                                                        <div class="row min-max-fields">
                                                            <div class="col-md-6">
                                                                <label>{{ __('Field Length (Min)') }}</label>
                                                                <input type="number" name="min_length" class="form-control" value="{{ $verification_field->min_length }}">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label>{{ __('Field Length (Max)') }}</label>
                                                                <input type="number" name="max_length" class="form-control" value="{{ $verification_field->max_length }}">
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="alert alert-info">
                                                            {{ __('Field type, min/max length, required and status can only be set in English.') }}
                                                        </div>
                                                    @endif

                                                    {{-- Field Values --}}
                                                    @php
                                                        $values = $isEnglish
                                                            ? ($verification_field->values ?? [])
                                                            : ($translated->value ?? []) ?? [];
                                                    @endphp

                                                    <div class="form-group">
                                                        <label>{{ __('Field Values111') }} ({{ $lang->name }})</label>
                                                        <select name="values[{{ $lang->id }}][]" data-tags="true" class="form-control select2" multiple>
                                                            @foreach($values as $val)
                                                                <option value="{{ $val }}" selected>{{ $val }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if(!$isEnglish)
                                                            <small class="text-muted">{{ __('Used for translatable fields like dropdown, radio, checkbox.') }}</small>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group mandatory">
                                            <div class="form-check form-switch  ">
                                                <input type="hidden" name="is_required" id="required" value="{{ $verification_field->is_required ? '1' : '0' }}">
                                                <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="required" {{ $verification_field->is_required ? 'checked' : '' }}>{{ __('Required') }}
                                                <label class="form-check-label" for="required"></label>
                                            </div>
                                        </div>
                                        <div class="col-md-6 form-group mandatory">
                                            <div class="form-check form-switch">

                                                <input type="hidden" name="status" id="status" value="{{ $verification_field->deleted_at ? '0' : '1' }}">

                                                <input class="form-check-input status-switch" type="checkbox" role="switch" aria-label="status"
                                                       {{ $verification_field->deleted_at ? '' : 'checked' }}>
                                                {{ __('Active') }}
                                                <label class="form-check-label" for="status"></label>
                                            </div>
                                        </div>
                                    <div class="col-md-12 text-end mb-3">
                                        <input type="submit" class="btn btn-primary" value="{{__("Save and Back")}}">
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            @if(in_array($verification_field->type,['radio','checkbox','dropdown']))
            <div class="col-md-12 col-sm-12">
                <div class="card">
                    <div class="card-body">
                        <table class="table table-borderless table-striped" id="table_list"
                               data-toggle="table" data-url="{{ route('seller-verification.value.show', $verification_field->id) }}"
                               data-click-to-select="true"
                               data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-search-align="right"
                               data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                               data-trim-on-search="false" data-responsive="true" data-sort-name="id"
                               data-escape="true"
                               data-sort-order="desc" data-query-params="queryParams"
                               data-use-row-attr-func="true" data-mobile-responsive="true">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                <th scope="col" data-field="value" data-align="center" data-sortable="true">{{ __('Value') }}</th>
                                <th scope="col" data-field="operate" data-escape="false" data-align="center" data-sortable="false" data-events="verificationFieldValueEvents">{{ __('Action') }}</th>
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        @endif
        {{-- add modal --}}
        @if(in_array($verification_field->type,['radio','checkbox','dropdown']))
            <div id="addModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
                 aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel1">{{ __('Add Values') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form action="{{ route('seller-verification.value.add',$verification_field->id) }}" class="create-form form-horizontal" enctype="multipart/form-data" method="POST" data-parsley-validate>
                                @csrf
                                <div class="col-md-12 form-group mandatory">
                                    <label for="values" class="mandatory form-label">{{ __('Field Values') }}</label>
                                    <input type="text" name="values" id="values" class="form-control" value="{{ old('values') }}" data-parsley-required="true">
                                </div>

                                <input type="hidden" name="field_id" id="field_id" value="{{ $verification_field->id }}">
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                                    <button type="submit" class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- /.modal-content -->
            </div>
            {{-- edit modal --}}
            <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel1">{{ __('Edit Verfication Field Values') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form action="{{ route('seller-verification.value.update',$verification_field->id) }}" class="edit-form form-horizontal" enctype="multipart/form-data" method="POST" data-parsley-validate>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="old_verification_field_value" id="old_verification_field_value"/>
                                <div class="col-md-12 form-group mandatory">
                                    <label for="new_verification_field_value" class="mandatory form-label">{{ __('Name') }}</label>
                                    <input type="text" name="new_verification_field_value" id="new_verification_field_value" class="form-control" value="" data-parsley-required="true">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                                    <button type="submit" class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- /.modal-content -->
                @endif
            </div>
        </div>
    </section>
@endsection
@section('script')
    <script>
        function updateVerificationFieldUI() {
    const type = $('select[name="type"]').val();
    const valuesTypes = ['radio', 'dropdown', 'checkbox'];

    $('.tab-pane').each(function () {
        const $tab = $(this);
        const $fieldValues = $tab.find('select[name^="values"]').closest('.form-group');
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
    updateVerificationFieldUI();
    $('select[name="type"]').on('change', updateVerificationFieldUI);
});

        function afterCustomFieldCreation() {
            setTimeout(function () {
                window.location.href = "{{route('seller-verification.verification-field')}}";
            }, 1000)
        }
    </script>
@endsection
