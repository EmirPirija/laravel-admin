@extends('layouts.main')

@section('title')
    {{ __('Cities') }}
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
        <div class="row m-3">
            <div class="col-12 text-end">
                <a href="{{ route('cities.translation') }}" class="btn btn-primary">
                    <i class="fa fa-language me-2"></i> {{ __('Translate Cities') }}
                </a>
            </div>
        </div>

        <div class="row">
            <form class="create-form" action="{{route('city.create')}}" method="POST" data-parsley-validate enctype="multipart/form-data">
                @csrf
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">{{__("Add City")}}</div>
                        <div class="card-body mt-3">
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="country" class="mandatory form-label">{{__("Country")}}</label>
                                    <select name="country_id" id="country" class="form-control form-select" data-placeholder="{{__("Select Country")}}">
                                        <option value="">{{__("Select Country")}}</option>
                                        @foreach ($countries as $country)
                                            <option value="{{ $country->id }}">{{ $country->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label for="state" class="mandatory form-label">{{__("State")}}</label>
                                    <select name="state_id" id="state" class="form-control form-select" data-placeholder="{{__("Select State")}}">
                                        <option value="">{{ __("Select State") }}</option>
                                    </select>
                                </div>
                            </div>
                            <div id="city-container" >
                                <div class="row city-input-group">
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label for="name" class="mandatory form-label mt-2">{{ __("City Name") }}</label><span class="text-danger">*</span>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="name[]" class="form-control me-2" placeholder="{{ __("Enter City name") }}">
                                        </div>
                                    </div>
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label for="latitude" class="mandatory form-label mt-2">{{ __("Latitude") }}</label>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="latitude[]" class="form-control me-2" placeholder="{{ __("Enter Latitude") }}">
                                        </div>
                                    </div>
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label for="longitude" class="mandatory form-label mt-2">{{ __("Longitude") }}</label>
                                        <div class="d-flex mb-2">
                                            <input type="text" name="longitude[]" class="form-control me-2" placeholder="{{ __("Enter Longitude") }}">
                                            <button type="button" class="btn btn-secondary add-city-button">+</button>
                                            <button type="button" class="btn btn-danger remove-city-button ms-2">-</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div id="map"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12 m-2 text-end">
                                <input type="submit" class="btn btn-primary" value="{{__("Create")}}">
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div id="filters">
                            <div class="row">
                                <div class="col-12 col-md-4">
                                    <label for="filter_country">{{__("Country")}}</label>
                                    <select class="form-control bootstrap-table-filter-control-country_name" id="filter_country">
                                        <option value="">{{__("All")}}</option>
                                        @foreach($countries as $country)
                                            <option value="{{$country->id}}">{{$country->name}}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="filter_state">{{__("State")}}</label>
                                    <select class="form-control bootstrap-table-filter-control-state_name" id="filter_state">
                                        <option value="">{{__("All")}}</option>
                                        @foreach($states as $state)
                                            <option value="{{$state->id}}">{{$state->name}}</option>
                                        @endforeach
                                    </select>
                                </div>

                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <table class="table-borderless table-striped" aria-describedby="mydesc" id="table_list"
                                       data-toggle="table" data-url="{{ route('cities.show',1) }}" data-click-to-select="true"
                                       data-side-pagination="server" data-pagination="true"
                                       data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                                       data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                                       data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false"
                                       data-responsive="true" data-sort-name="id" data-sort-order="desc"
                                       data-pagination-successively-size="3" data-table="cities" data-status-column="deleted_at"
                                       data-escape="true"
                                       data-filter-control="true"
                                       data-toolbar="#filters"
                                       data-filter-control-container="#filters"
                                       data-show-export="true" data-export-options='{"fileName": "city-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                                       data-mobile-responsive="true">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                        <th scope="col" data-field="name" data-sortable="true">{{ __('Name') }}</th>
                                        <th scope="col" data-field="country_name" data-sortable="true" data-filter-name="country_id" data-filter-control="select" data-filter-data="">{{ __('Country Name') }}</th>
                                        <th scope="col" data-field="state_name" data-sortable="true" data-filter-name="state_id" data-filter-control="select" data-filter-data="">{{ __('State Name') }}</th>
                                        <th scope="col" data-field="longitude" data-sortable="false">{{ __('Longitude') }}</th>
                                        <th scope="col" data-field="latitude" data-sortable="false">{{ __('Latitude') }}</th>
                                        <th scope="col" data-field="country.emoji">{{ __('Flag') }}</th>
                                        <th scope="col" data-field="operate" data-sortable="false" data-escape="false" data-events="cityEvents">{{ __('Action') }}</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                @can('city-update')
                <!-- EDIT MODEL MODEL -->
                    <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="myModalLabel1">{{ __('Edit City') }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <form id="edit-form" class="edit-form" action="" method="POST" enctype="multipart/form-data">
                                        @csrf
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label for="country" class="mandatory form-label">{{__("Country")}}</label>
                                                <select name="country_id" id="edit_country" class="form-control country form-select" data-placeholder="{{__("Select Country")}}">
                                                    <option value="">Select Country</option>
                                                    @foreach ($countries as $country)
                                                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label for="state" class="mandatory form-label">{{__("State")}}</label>
                                                <select name="state_id" id="edit_state" class="form-control form-select" data-placeholder="{{__("Select State")}}">
                                                    @foreach ($states as $state)
                                                        <option value="{{ $state->id }}">{{ $state->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="name" class="mandatory form-label">City Name</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="name" class="form-control me-2" id="edit_name" placeholder="{{__("Enter City name")}}">
                                                </div>
                                            </div>
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="latitude" class="mandatory form-label">Latitude</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="latitude" id="edit_latitude" class="form-control me-2" placeholder="{{__("Enter Latitude")}}">
                                                </div>
                                            </div>
                                            <div class="form-group col-md-4 col-sm-12">
                                                <label for="longitude" class="mandatory form-label">Longitude</label>
                                                <div class="d-flex mb-2">
                                                    <input type="text" name="longitude" id="edit_longitude" class="form-control me-2" placeholder="{{__("Enter Longitude")}}">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <div id="edit_map" style="height: 400px;"></div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                                            <button type="submit" class="btn btn-primary waves-effect waves-light">{{ __('Save') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endcan
    </section>
@endsection
@section('script')
    <script>
        $(document).ready(function() {
            // Initialize map with default coordinates
            const map = window.mapUtils.initializeMap('map', 0, 0);

            window.updateAreaCoordinates = function(lat, lng) {
            // Get the last area input group (the one being edited)
            const $areaGroup = $('.city-input-group').last();

            // Update latitude and longitude fields
            $areaGroup.find('input[name="latitude[]"]').val(lat);
            $areaGroup.find('input[name="longitude[]"]').val(lng);
        };
        });
    </script>
@endsection
