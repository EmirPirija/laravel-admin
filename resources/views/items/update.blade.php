@extends('layouts.main')

@section('title')
    {{ __('Update Advertisements') }}
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
        <div class="card-body">
            <form method="POST" action="{{ route('advertisement.update', $item->id) }}" enctype="multipart/form-data">
                    @csrf
                @method('PUT')
                <input type="hidden" name="id" value="{{ $item->id }}">
&nbsp;
&nbsp;

                <ul class="nav nav-tabs" id="editItemTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#listing">{{ __('Listing Details') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#custom">{{ __('Other Details') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#images">{{ __('Product Images') }}</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#address">{{ __('Address') }}</a></li>
                </ul>
&nbsp;
&nbsp;

                <div class="tab-content pt-3">
                    {{-- Listing Details --}}
                    <div class="tab-pane fade show active" id="listing">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label>{{ __('Name') }}</label>
                                <input type="text" name="name" value="{{ $item->name }}" class="form-control">
                            </div>
                            <div class="col-6 mb-3">
                                <label>{{ __('Slug') }}</label>
                                <input type="text" name="slug" value="{{ $item->slug }}" class="form-control" disabled>
                            </div>
                            <div class="col-6 mb-3">
                                <label>{{ __('Category') }}</label>
                                <select name="category_id" class="form-control" id="category-select">
                                    @if($item->category)
                                        <option value="{{ $item->category->id }}" selected>{{ $item->category->name }}</option>
                                    @else
                                        <option value="">{{ __('Select Category') }}</option>
                                    @endif
                                </select>

                                <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#subcategory-modal">
                                    {{ __('Change') }}
                                </button>
                            </div>
                           @php
                                $isJobCategory = $item->category && $item->category->is_job_category;
                                $isPriceOptional = $item->category && $item->category->price_optional;
                            @endphp

                            <div class="col-6 mb-3" id="price-field" style="{{ ($isJobCategory || $isPriceOptional) ? 'display: none;' : '' }}">
                                <label>{{ __('Price') }}</label>
                                <input type="number" name="price" value="{{ $item->price }}" class="form-control">
                            </div>

                            <div class="col-6 mb-3" id="salary-fields" style="{{ ($isJobCategory || $isPriceOptional) ? '' : 'display: none;' }}">
                                <label>{{ __('Min Salary') }}</label>
                                <input type="number" name="min_salary" value="{{ old('min_salary', $item->min_salary ?? '') }}" class="form-control mb-2">
                                <label>{{ __('Max Salary') }}</label>
                                <input type="number" name="max_salary" value="{{ old('max_salary', $item->max_salary ?? '') }}" class="form-control">
                            </div>

                            <div class="col-6 mb-3">
                                <label>{{ __('Phone Number') }}</label>
                                <input type="text" name="contact" value="{{ $item->contact }}" class="form-control" readonly>
                            </div>
                            <div class="col-12 mb-3">
                                <label>{{ __('Description') }}</label>
                                <textarea name="description" class="form-control">{{ $item->description }}</textarea>
                            </div>

                            {{-- Scheduled Publishing --}}
                            <div class="col-6 mb-3">
                                <label>{{ __('Schedule Publishing') }}</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="enable-scheduling" name="enable_scheduling" 
                                        {{ $item->status === 'scheduled' || old('enable_scheduling') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enable-scheduling">
                                        {{ __('Schedule this ad for later') }}
                                    </label>
                                </div>
                                <input type="datetime-local" name="scheduled_at" id="scheduled-at-input" 
                                    class="form-control" 
                                    value="{{ old('scheduled_at', $item->scheduled_at ? $item->scheduled_at->format('Y-m-d\TH:i') : '') }}"
                                    style="display: {{ ($item->status === 'scheduled' || old('enable_scheduling')) ? 'block' : 'none' }};"
                                    min="{{ now()->format('Y-m-d\TH:i') }}">
                                <small class="text-muted" id="scheduling-help" style="display: {{ ($item->status === 'scheduled' || old('enable_scheduling')) ? 'block' : 'none' }};">
                                    {{ __('The ad will be automatically published at the selected date and time.') }}
                                </small>
                                @if($item->status === 'scheduled' && $item->scheduled_at)
                                    <div class="alert alert-info mt-2 p-2">
                                        <small><i class="fa fa-clock"></i> {{ __('Currently scheduled for:') }} {{ $item->scheduled_at->format('d.m.Y H:i') }}</small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
&nbsp;
&nbsp;

                    {{-- Other Details - Custom Fields --}}
                   <div class="tab-pane fade" id="custom">
                        <div class="row">
                            @forelse($custom_fields as $field)
                                <div class="col-md-6 mb-3">
                                    <label>{{ $field->name }} @if($field->required) <span class="text-danger">*</span> @endif</label>

                                    @php
                                        $isRequired = '';
                                    @endphp

                                    @if($field->type === 'textbox')
                                        <input type="text" name="custom_fields[{{ $field->id }}]" class="form-control" value="{{ $field->value ?? '' }}" {{ $isRequired }}>

                                    @elseif($field->type === 'number')
                                        <input type="number" name="custom_fields[{{ $field->id }}]" class="form-control" value="{{ $field->value ?? '' }}" {{ $isRequired }}>

                                    @elseif($field->type === 'fileinput')
                                        <input type="file" name="custom_field_files[{{ $field->id }}]" class="form-control" {{ $isRequired }}>
                                        @if(!empty($field->value))
                                            <img src="{{ $field->value[0] ?? '' }}" alt="" width="100">
                                        @endif

                                    @elseif($field->type === 'dropdown' || $field->type === 'radio')
                                        @php $options = is_array($field->values) ? $field->values : json_decode($field->values, true); @endphp
                                        <select name="custom_fields[{{ $field->id }}]" class="form-select" {{ $isRequired }}>
                                            <option value="">{{ __('Select') }}</option>
                                            @foreach($options as $option)
                                                <option value="{{ $option }}" {{ $field->value == $option ? 'selected' : '' }}>{{ $option }}</option>
                                            @endforeach
                                        </select>

                                    @elseif($field->type === 'checkbox')
                                        @php $options = is_array($field->values) ? $field->values : json_decode($field->values, true); @endphp
                                        @foreach($options as $option)
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="custom_fields[{{ $field->id }}][]" value="{{ $option }}"
                                                    {{ is_array($field->value) && in_array($option, $field->value) ? 'checked' : '' }} {{ $isRequired }}>
                                                <label class="form-check-label">{{ $option }}</label>
                                            </div>
                                        @endforeach
                                    @endif

                                </div>
                            @empty
                                <p class="text-muted">{{ __('No custom fields for this category.') }}</p>
                            @endforelse
                        </div>
                    </div>

&nbsp;
&nbsp;

                    {{-- Product Images --}}
                    <div class="tab-pane fade" id="images">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label>{{ __('Main Image') }}</label>
                                <input type="file" name="image" class="form-control">
                                 <img src={{$item->image}} width="80" class="mb-2">
                            </div>
                            <div class="col-6 mb-3">
                                <label>{{ __('Other Images') }}</label>
                                <input type="file" name="gallery_images[]" class="form-control" multiple>
                                 @foreach ($item->gallery_images as $img)
                                <div class="mb-2">
                                    <img src={{$img->image}} width="80">
                                    <input type="checkbox" name="delete_item_image_id[]" value="{{ $img->id }}"> Remove
                                </div>
                        @endforeach
                            </div>
                             <div class="col-12 mb-3">
                                <label for="video_link">{{ __('Video Link') }}</label>
                                <input type="url" name="video_link" id="video_link" class="form-control"
                                    value="{{ old('video_link', $item->video_link ?? '') }}"
                                    placeholder="https://www.youtube.com/watch?v=...">
                            </div>
                        </div>

                    </div>
&nbsp;
&nbsp;

                    {{-- Address --}}
                        <div class="tab-pane fade" id="address">
                            <div class="row">
                                <!-- LEFT SIDE: Manual Input -->
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="country" class="form-label">{{ __('Country') }}</label>
                                       <select class="form-control select2" id="country_item" name="country">
                                            <option value="">--Select Country--</option>
                                            @foreach($countries as $country)
                                                <option value="{{ $country->id }}" {{ $item->country == $country->name ? 'selected' : '' }}>
                                                    {{ $country->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <input type="hidden" id="item_state" value="{{ $item->state }}">
                                    <div class="form-group mb-3">
                                        <label for="state" class="form-label">{{ __('State') }}</label>
                                        <select class="form-control select2" id="state_item" name="state">
                                            <option value="">--Select State--</option>
                                        </select>
                                    </div>
                                    <input type="hidden" id="item_city" value="{{ $item->city }}">
                                    <div class="form-group mb-3">
                                        <label for="city" class="form-label">{{ __('City') }}</label>
                                        <select class="form-control select2" id="city" name="city">
                                            <option value="">--Select City--</option>
                                        </select>
                                    </div>

                                   <div class="form-group mb-3">
                                    <label for="manual_address" class="form-label">{{ __('Add Address') }}</label>
                                    <input type="text" id="manual_address" name="manual_address" class="form-control" placeholder="{{ __('Type manual address') }}">
                                </div>

                                    <input type="hidden" id="latitude-input" name="latitude" />
                                    <input type="hidden" id="longitude-input" name="longitude" />
                                    <input type="hidden" name="country_input" id="country-input">
                                    <input type="hidden" name="state_input" id="state-input">
                                    <input type="hidden" name="city_input" id="city-input">

                                </div>

                                <!-- RIGHT SIDE: Map -->
                                <div class="col-md-6">
                                    <div class="form-group mb-2">
                                        <label class="form-label">{{ __('Address From Map') }}</label>
                                        <input type="text" class="form-control" id="address-input" readonly>
                                    </div>
                                    <label class="form-label">{{ __('Select Location on Map') }}</label>
                                    <div id="map" style="height: 400px; border: 1px solid #ddd;"></div>
                                </div>
                            </div>
                        </div>
&nbsp;
&nbsp;


                </div>
            </hr>
                <div class="row mt-1">
                <div class="col-md-12">
                    <div class="form-group">
                        <label for="admin_edit_reason">{{ __('Reason for Admin Edit') }} <span class="text-danger">*</span></label>
                        <textarea
                            name="admin_edit_reason"
                            id="admin_edit_reason"
                            class="form-control"
                            rows="3"
                            required
                        >{{ old('admin_edit_reason', $item->admin_edit_reason ?? '') }}</textarea>

                        @error('admin_edit_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

                <div class="mt-3">
                     <button type="submit" class="btn btn-primary">{{ __('Update Item') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="subcategory-modal" tabindex="-1" role="dialog" aria-labelledby="subcategory-modal-label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="subcategory-modal-label">{{ __('Select Category or Subcategory') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
          <div class="modal-body">
            <div class="current-category mb-3">
                <label>{{ __('Current Category:') }}</label>
                @if($item->category)
                <input type="text" class="form-control" value="{{ $item->category->name }}" readonly>
                @else
                <input type="text" class="form-control" value="Select Category" readonly>
                @endif
            </div>
            <div class="categories-list">
                @include('items.treeview', [
                    'categories' => $categories,
                    'selected_category' => $item->category?->id ?? ''
                ])
            </div>
        </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                <button type="button" class="btn btn-primary" id="save-subcategory">{{ __('Save changes') }}</button>
            </div>
        </div>
    </div>
</div>
</section>
@endsection
@section('script')
<script>
$(document).ready(function() {

   $('#category-select').on('change', function () {
    let categoryId = $(this).val();
    $.ajax({
        url: `/get-custom-fields/${categoryId}`,
        type: 'GET',
        success: function (response) {
            let html = '';
             html += `<div class="row">`;
           response.fields.forEach(function (field) {
                    const isRequired = '';
                    html += `<div class="col-md-6 mb-3">`;
                    html += `<label>${field.name}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

                if (field.type === 'textbox') {
                    html += `<input type="text" name="custom_fields[${field.id}]" class="form-control" ${isRequired} value="${field.value ?? ''}">`;
                } else if (field.type === 'number') {
                    html += `<input type="number" name="custom_fields[${field.id}]" class="form-control" ${isRequired} value="${field.value ?? ''}">`;
                } else if (field.type === 'fileinput') {
                    if (field.value) {
                        html += `<img src="${field.value[0] ?? ''}" alt="" width="100">`;
                    }
                html += `<input type="file" name="custom_field_files[${field.id}]" class="form-control" ${isRequired}>`;
                } else if (field.type === 'dropdown' || field.type === 'radio') {
                    const options = Array.isArray(field.values) ? field.values : JSON.parse(field.values ?? '[]');
                    html += `<select name="custom_fields[${field.id}]" class="form-select" ${isRequired}>`;
                    html += `<option value="">Select</option>`;
                    options.forEach(option => {
                        const selected = (field.value === option) ? 'selected' : '';
                        html += `<option value="${option}" ${selected}>${option}</option>`;
                    });
                    html += `</select>`;
                } else if (field.type === 'checkbox') {
                    const options = Array.isArray(field.values) ? field.values : JSON.parse(field.values ?? '[]');
                    options.forEach(option => {
                        const checked = Array.isArray(field.value) && field.value.includes(option) ? 'checked' : '';
                        html += `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="custom_fields[${field.id}][]" value="${option}" ${checked} ${isRequired}>
                                <label class="form-check-label">${option}</label>
                            </div>
                        `;
                    });
                }

                html += `</div>`;
            });


            if (!html) {
                html = `<p class="text-muted">No custom fields for this category.</p>`;
            }
              html += `</div>`;
            $('#custom').html(html);

             if (response.is_job_category || response.price_optional) {
                $('#price-field').hide();
                $('#salary-fields').show();
            } else {
                $('#price-field').show();
                $('#salary-fields').hide();
            }
        }
    });
});


    // Toggle subcategories on click
    $('.toggle-button').on('click', function() {
        $(this).siblings('.subcategories').toggle();
        $(this).toggleClass('open');
    });
});

</script>
<script>
    document.querySelectorAll('input[name="selected_category"]').forEach(radio => {
        radio.addEventListener('change', function () {
            const selectedName = this.closest('label').innerText.trim();
            document.querySelector('.current-category input').value = selectedName;
        });
    });
</script>

<script>
    document.getElementById('save-subcategory').addEventListener('click', function () {
        const selectedRadio = document.querySelector('input[name="selected_category"]:checked');
        if (selectedRadio) {
            const selectedId = selectedRadio.value;
            const selectedName = selectedRadio.closest('label').innerText.trim();

            const categorySelect = document.getElementById('category-select');

            // Clear current options
            categorySelect.innerHTML = '';

            // Add the newly selected category
            const option = document.createElement('option');
            option.value = selectedId;
            option.text = selectedName;
            option.selected = true;

            categorySelect.appendChild(option);

             $('#category-select').trigger('change');

        // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('subcategory-modal'));
            modal.hide();
            }
    });
</script>

<script>
let map, marker;

function initMap() {
        const defaultLat = parseFloat('{{ $item->latitude ?? '0' }}') || 20.5937;
        const defaultLng = parseFloat('{{ $item->longitude ?? '0' }}') || 78.9629;


    map = L.map('map').setView([defaultLat, defaultLng], 6);

    // Load tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Add marker
    marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);

    updateLatLngInputs(defaultLat, defaultLng);
    fetchAddressFromCoords(defaultLat, defaultLng);

    marker.on('dragend', function (e) {
        const pos = marker.getLatLng();
        updateLatLngInputs(pos.lat, pos.lng);
        fetchAddressFromCoords(pos.lat, pos.lng);
    });

        const provider = new GeoSearch.OpenStreetMapProvider();
        const search = new GeoSearch.GeoSearchControl({
            provider: provider,
            style: 'bar',
            autoComplete: true,
            searchLabel: 'Enter address',
            showMarker: false,
        });
    map.addControl(search);

    map.on('geosearch/showlocation', function (result) {
        const { x: lng, y: lat, label } = result.location;
        marker.setLatLng([lat, lng]);
        map.setView([lat, lng], 15);
        updateLatLngInputs(lat, lng);
        document.getElementById("address-input").value = label;
        fetchAddressFromCoords(lat, lng); // Optional, if label lacks full info
    });
}

function updateLatLngInputs(lat, lng) {
    document.getElementById("latitude-input").value = lat;
    document.getElementById("longitude-input").value = lng;
}

// Reverse geocoding using Nominatim
function fetchAddressFromCoords(lat, lng) {
    fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`)
        .then(res => res.json())
        .then(data => {
            const address = data.address;
            
            const fullAddress = data.display_name || '';

            // Fill hidden inputs
            document.getElementById("address-input").value = fullAddress;
            document.getElementById("country-input").value = address.country || '';
            document.getElementById("state-input").value = address.state || '';
            document.getElementById("city-input").value = address.city || address.town || address.village || address.state_district || '';

            // ✅ Select dropdowns (country, then load states, then load cities)
            let countryName = address.country || '';
            let stateName   = address.state || '';
            let cityName    = address.city || address.town || address.village || address.state_district || '';
            
            // --- COUNTRY ---
            let countryOption = $(`#country_item option`).filter(function () {
                return $(this).text().trim().toLowerCase() === countryName.toLowerCase();
            });
            if (countryOption.length) {
                $('#country_item').val(countryOption.val()).trigger('change');
            }

            // --- STATE ---
            // wait a little because states load after country change
            setTimeout(function () {
                let stateOption = $(`#state_item option`).filter(function () {
                    return $(this).text().trim().toLowerCase() === stateName.toLowerCase();
                });
                if (stateOption.length) {
                    $('#state_item').val(stateOption.val()).trigger('change');
                }
            }, 1500);

            // --- CITY ---
            // wait a little because cities load after state change
            setTimeout(function () {
                let cityOption = $(`#city option`).filter(function () {
                    return $(this).text().trim().toLowerCase() === cityName.toLowerCase();
                });
                if (cityOption.length) {
                    $('#city').val(cityOption.val()).trigger('change');
                }
            }, 3500);
        });
}

// Initialize when tab is opened
document.addEventListener("DOMContentLoaded", function () {
    const tab = document.querySelector('[href="#address"]');
    if (tab) {
        tab.addEventListener("click", () => {
            setTimeout(() => initMap(), 300); // Delay for hidden tab
        });
    } else {
        // fallback if map is visible by default
        initMap();
    }
});
</script>

<script>
// Scheduling toggle handler
$(document).ready(function() {
    $('#enable-scheduling').on('change', function() {
        if ($(this).is(':checked')) {
            $('#scheduled-at-input').show().attr('required', true);
            $('#scheduling-help').show();
        } else {
            $('#scheduled-at-input').hide().removeAttr('required').val('');
            $('#scheduling-help').hide();
        }
    });

    // Initialize on page load
    if ($('#enable-scheduling').is(':checked')) {
        $('#scheduled-at-input').show().attr('required', true);
        $('#scheduling-help').show();
    }
});
</script>

@endsection
