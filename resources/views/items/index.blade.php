@extends('layouts.main')

@section('title')
    {{ __('Advertisements') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6">
                <h4>@yield('title')</h4>
            </div>
             <div class="col-12 col-md-6 d-flex justify-content-end">
                <a class="btn btn-primary me-2" href="{{ route('advertisement.create')}}">{{ __('Create Advertisement') }}</a>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-success" id="btn-active-ads">
                                {{ __('Active Advertisements') }}
                            </button>
                            <button type="button" class="btn btn-primary" id="btn-requested-ads">
                                {{ __('Requested Advertisements') }}
                            </button>
                            <button type="button" class="btn btn-secondary active" id="btn-all-ads">
                                {{ __('All Advertisements') }}
                            </button>
                        </div>

                        <div id="filters" class="d-flex flex-wrap align-items-end gap-2 mb-3">
                            <div class="col-md-2">
                                <label for="filter">{{ __('Status') }}</label>
                                <select class="form-control" id="filter" data-field="status">
                                    <option value="">{{ __('All') }}</option>
                                    <option value="approved">{{ __('Approved') }}</option>
                                    <option value="review">{{ __('Under Review') }}</option>
                                    <option value="sold out">{{ __('Sold Out') }}</option>
                                    <option value="expired">{{ __('Expired') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                    <option value="soft rejected">{{ __('Soft Rejected') }}</option>
                                    <option value="permanent rejected">{{ __('Permanent Rejected') }}</option>
                                    <option value="resubmitted">{{ __('Resubmitted') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="filter-featured-premium">{{ __('Featured / Premium') }}</label>
                                <select class="form-control bootstrap-table-filter-control-featured_status"
                                    id="filter_featured_premium">
                                    <option value="">{{ __('All') }}</option>
                                    <option value="featured">{{ __('Featured') }}</option>
                                    <option value="premium">{{ __('Premium') }}</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_country">{{ __('Country') }}</label>
                                <select class="form-control bootstrap-table-filter-control-country"
                                    id="filter_country_item_test">
                                    <option value="">{{ __('All') }}</option>
                                    @foreach ($countries as $country)
                                        <option value="{{ $country->name }}">{{ $country->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_state">{{ __('State') }}</label>
                                <select name="state_id" class="form-control bootstrap-table-filter-control-state"
                                    id="filter_state_item">
                                    <option value="">{{ __('All') }}</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_city">{{ __('City') }}</label>
                                <select name="city_id" class="form-control bootstrap-table-filter-control-city"
                                    id="filter_city_item">
                                    <option value="">{{ __('All') }}</option>
                                </select>
                            </div>
                        </div>
                        <table class="table-borderless table-striped" aria-describedby="mydesc" id="table_list"
                            data-toggle="table" data-url="{{ route('advertisement.show', 'approved') }}"
                            data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-show-columns="true"
                            data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1"
                            data-fixed-right-number="1" data-trim-on-search="false" data-escape="true"
                            data-responsive="true" data-sort-name="id" data-sort-order="desc"
                            data-pagination-successively-size="3" data-table="items" data-status-column="deleted_at"
                            data-show-export="true"
                            data-export-options='{"fileName": "item-list","ignoreColumn": ["operate"]}'
                            data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                            data-mobile-responsive="true" data-filter-control="true"
                            data-filter-control-container="#filters" data-toolbar="#filters"
                            data-query-params="itemListQueryParams">
                            <thead class="thead-dark">
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="name" data-sortable="true">{{ __('Name') }}</th>
                                    <th scope="col" data-field="description" data-align="center" data-sortable="true"
                                        data-formatter="descriptionFormatter">{{ __('Description') }}</th>
                                    <th scope="col" data-field="user.profile" data-formatter="imageFormatter">{{ __('Profile') }}</th>
                                    <th scope="col" data-field="user.name" data-sort-name="user_name"
                                        data-sortable="true">{{ __('User') }}</th>
                                    <th scope="col" data-field="price" data-sortable="true">{{ __('Price') }}</th>
                                    <th scope="col" data-field="min_salary" data-sortable="true" data-visible="false">{{ __('Min Salary') }}
                                    </th>
                                    <th scope="col" data-field="max_salary" data-sortable="true" data-visible="false">{{ __('Max Salary') }}
                                    </th>
                                    <th scope="col" data-field="category.name" data-sortable="true" >{{ __('Category') }}
                                    </th>
                                    <th scope="col" data-field="image" data-sortable="false" data-escape="false"
                                        data-formatter="imageFormatter">{{ __('Image') }}</th>
                                    <th scope="col" data-field="gallery_images" data-sortable="false"
                                        data-formatter="galleryImageFormatter" data-escape="false">
                                        {{ __('Other Images') }}</th>
                                    <th scope="col" data-field="latitude" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Latitude') }}</th>
                                    <th scope="col" data-field="longitude" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Longitude') }}</th>
                                    <th scope="col" data-field="address" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Address') }}</th>
                                    <th scope="col" data-field="contact" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Contact') }}</th>
                                    <th scope="col" data-field="country" data-sortable="true"
                                        data-filter-control="select" data-filter-data="" data-visible="true">
                                        {{ __('Country') }}</th>
                                    <th scope="col" data-field="state" data-sortable="true" data-visible="true">
                                        {{ __('State') }}</th>
                                    <th scope="col" data-field="city" data-sortable="true"
                                        data-filter-control="select" data-filter-data="" data-visible="true">
                                        {{ __('City') }}</th>
                                    <th scope="col" data-field="featured_status" data-sortable="false"
                                        data-filter-control="select" data-filter-data=""
                                        data-formatter="featuredItemStatusFormatter">{{ __('Featured/Premium') }}</th>
                                    <th scope="col" data-field="status" data-sortable="false"
                                        data-escape="false"
                                        data-formatter="itemStatusFormatter">{{ __('Status') }}</th>
                                    @can('advertisement-update')
                                        <th scope="col" data-field="active_status" data-sortable="true"
                                            data-sort-name="deleted_at" data-visible="true" data-escape="false"
                                            data-formatter="statusSwitchFormatter">{{ __('Active') }}</th>
                                    @endcan
                                    <th scope="col" data-field="rejected_reason" data-sortable="true"
                                        data-visible="false" data-switchable="false">{{ __('Rejected Reason') }}</th>
                                    <th scope="col" data-field="expiry_date" data-sortable="true"
                                        data-visible="true">{{ __('Expiry Date') }}</th>
                                    <th scope="col" data-field="created_at" data-sortable="false" data-visible="true"
                                        data-switchable="false" data-formatter="dateFormatter">{{ __('Created At') }}</th>
                                    <th scope="col" data-field="updated_at" data-sortable="false"
                                        data-visible="false" data-switchable="false">{{ __('Updated At') }}</th>
                                    <th scope="col" data-field="user_id" data-sortable="false" data-visible="false"
                                        data-switchable="false">{{ __('User ID') }}</th>
                                    <th scope="col" data-field="category_id" data-sortable="true"
                                        data-visible="false" data-switchable="false">{{ __('Category ID') }}</th>
                                    <th scope="col" data-field="likes" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Likes') }}</th>
                                    <th scope="col" data-field="clicks" data-sortable="true" data-visible="false"
                                        data-switchable="false">{{ __('Clicks') }}</th>
                                    @canany(['advertisement-update', 'advertisement-delete'])
                                        <th scope="col" data-field="operate" data-align="center" data-sortable="false"
                                            data-events="itemEvents" data-escape="false">{{ __('Action') }}</th>
                                    @endcanany
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Advertisement Details') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="center" id="custom_fields"></div>
                    </div>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>
        <div id="editStatusModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Status') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="edit-form" action="" method="POST"
                            data-success-function="updateApprovalSuccess">
                            @csrf
                            <div class="row">
                                <div class="col-md-12">
                                    <select name="status" class="form-select" id="status" aria-label="status">
                                        <option value="review">{{ __('Under Review') }}</option>
                                        <option value="approved">{{ __('Approve') }}</option>
                                        <option value="soft rejected">{{ __('Soft Rejected') }}</option>
                                        <option value="permanent rejected">{{ __('Permanent Rejected') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div id="rejected_reason_container" class="col-md-12" style="display: none;">
                                <label for="rejected_reason" class="mandatory form-label">{{ __('Reason') }}</label>
                                <textarea name="rejected_reason" id="rejected_reason" class="form-control" placeholder={{ __('Reason') }}></textarea>
                                {{-- <input type="text" name="rejected_reason" id="rejected_reason" class="form-control"> --}}
                            </div>
                            <input type="submit" value="{{ __('Save') }}" class="btn btn-primary mt-3">
                        </form>
                    </div>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>
    </section>
@endsection
@section('script')
    <script>
        function updateApprovalSuccess() {
            $('#editStatusModal').modal('hide');
        }

        // Custom queryParams function for items table to preserve filters during pagination
        function itemListQueryParams(params) {
            // Get current filter values from filter controls
            const currentFilters = {};
            
            // Get status filter based on button mode and dropdown selection
            const statusFilterValue = $('#filter').val();
            
            if (window.itemStatusFilterMode === 'active') {
                // Active mode: always show approved (ignore dropdown)
                currentFilters.status = 'approved';
            } else if (window.itemStatusFilterMode === 'requested') {
                // Requested mode: if dropdown has a value, use it (it's already not approved)
                // Otherwise, use status_not: 'approved'
                if (statusFilterValue) {
                    currentFilters.status = statusFilterValue;
                } else {
                    currentFilters.status_not = 'approved';
                }
            } else {
                // All mode: use dropdown value if selected
                if (statusFilterValue) {
                    currentFilters.status = statusFilterValue;
                }
            }
            
            // FIRST: Get all non-status filters (country, state, city, featured_status)
            // These should ALWAYS be preserved regardless of button mode
            
            // Get featured/premium filter - try multiple selectors
            let featuredStatus = $('#filter_featured_premium').val() || 
                                $('.bootstrap-table-filter-control-featured_status').val() || 
                                $('select[data-field="featured_status"]').val() || 
                                $('select.bootstrap-table-filter-control-featured_status').val() || '';
            
            // Get country filter - try multiple selectors
            let country = $('#filter_country_item_test').val() || 
                         $('.bootstrap-table-filter-control-country').val() || 
                         $('select[data-field="country"]').val() || 
                         $('select.bootstrap-table-filter-control-country').val() || '';
            
            // Get state filter - try multiple selectors
            let state = $('#filter_state_item').val() || 
                       $('.bootstrap-table-filter-control-state').val() || 
                       $('select[data-field="state"]').val() || 
                       $('select.bootstrap-table-filter-control-state').val() || '';
            
            // Get city filter - try multiple selectors
            let city = $('#filter_city_item').val() || 
                      $('.bootstrap-table-filter-control-city').val() || 
                      $('select[data-field="city"]').val() || 
                      $('select.bootstrap-table-filter-control-city').val() || '';
            
            // Add non-status filters if they have values (always preserve these)
            if (featuredStatus && featuredStatus.trim() !== '') {
                currentFilters.featured_status = featuredStatus.trim();
            }
            if (country && country.trim() !== '') {
                currentFilters.country = country.trim();
            }
            if (state && state.trim() !== '') {
                currentFilters.state = state.trim();
            }
            if (city && city.trim() !== '') {
                currentFilters.city = city.trim();
            }
            
            // Build query params
            const queryParams = {
                limit: params.limit,
                offset: params.offset,
                order: params.order,
                search: params.search,
                sort: params.sort
            };
            
            // Add filter if we have any filters
            if (Object.keys(currentFilters).length > 0) {
                queryParams.filter = JSON.stringify(currentFilters);
            }
            
            return queryParams;
        }

        $(document).ready(function() {
            // Global variable to track status filter mode
            window.itemStatusFilterMode = 'all'; // 'all', 'active', 'requested' - default to 'all'
            
            // Global object to track intended filter values (to prevent Bootstrap Table from resetting them)
            window.intendedFilters = {
                country: '',
                state: '',
                city: '',
                featuredStatus: '',
                status: ''
            };

            // Function to get current filters from filter controls and merge with status filter
            function getMergedFilters() {
                // Get current filter values from filter controls
                const currentFilters = {};
                
                // FIRST: Get all non-status filters (country, state, city, featured_status)
                // These should ALWAYS be preserved regardless of button mode
                
                // Get featured/premium filter - try multiple selectors, fallback to intended filter
                let featuredStatus = $('#filter_featured_premium').val() || 
                                    $('.bootstrap-table-filter-control-featured_status').val() || 
                                    $('select[data-field="featured_status"]').val() || 
                                    $('select.bootstrap-table-filter-control-featured_status').val() || 
                                    (window.intendedFilters ? window.intendedFilters.featuredStatus : '') || '';
                
                // Get country filter - try multiple selectors, fallback to intended filter
                let country = $('#filter_country_item_test').val() || 
                             $('.bootstrap-table-filter-control-country').val() || 
                             $('select[data-field="country"]').val() || 
                             $('select.bootstrap-table-filter-control-country').val() || 
                             (window.intendedFilters ? window.intendedFilters.country : '') || '';
                
                // Get state filter - try multiple selectors, fallback to intended filter
                let state = $('#filter_state_item').val() || 
                           $('.bootstrap-table-filter-control-state').val() || 
                           $('select[data-field="state"]').val() || 
                           $('select.bootstrap-table-filter-control-state').val() || 
                           (window.intendedFilters ? window.intendedFilters.state : '') || '';
                
                // Get city filter - try multiple selectors, fallback to intended filter
                let city = $('#filter_city_item').val() || 
                          $('.bootstrap-table-filter-control-city').val() || 
                          $('select[data-field="city"]').val() || 
                          $('select.bootstrap-table-filter-control-city').val() || 
                          (window.intendedFilters ? window.intendedFilters.city : '') || '';
                
                // Add non-status filters if they have values (always preserve these)
                if (featuredStatus && featuredStatus.trim() !== '') {
                    currentFilters.featured_status = featuredStatus.trim();
                }
                if (country && country.trim() !== '') {
                    currentFilters.country = country.trim();
                }
                if (state && state.trim() !== '') {
                    currentFilters.state = state.trim();
                }
                if (city && city.trim() !== '') {
                    currentFilters.city = city.trim();
                }
                
                // SECOND: Build status filter based on button mode and dropdown
                const statusFilterValue = $('#filter').val() || '';
                
                if (window.itemStatusFilterMode === 'active') {
                    // Active mode: always show approved (ignore dropdown)
                    currentFilters.status = 'approved';
                } else if (window.itemStatusFilterMode === 'requested') {
                    // Requested mode: if dropdown has a value, use it (it's already not approved)
                    // Otherwise, use status_not: 'approved'
                    if (statusFilterValue && statusFilterValue.trim() !== '') {
                        currentFilters.status = statusFilterValue.trim();
                    } else {
                        currentFilters.status_not = 'approved';
                    }
                } else {
                    // All mode: use dropdown value if selected
                    if (statusFilterValue && statusFilterValue.trim() !== '') {
                        currentFilters.status = statusFilterValue.trim();
                    }
                }
                
                return Object.keys(currentFilters).length > 0 ? currentFilters : null;
            }

            // Function to update button active states
            function updateButtonStates(activeButton) {
                $('#btn-active-ads, #btn-requested-ads, #btn-all-ads').removeClass('active');
                $(activeButton).addClass('active');
            }

            // Function to store current filter values
            function storeFilterValues() {
                // Try to get values from multiple sources (direct IDs and Bootstrap Table controls)
                // Also check global intendedFilters as fallback
                const stored = {
                    featuredStatus: $('#filter_featured_premium').val() || 
                                 $('.bootstrap-table-filter-control-featured_status').val() || 
                                 window.intendedFilters.featuredStatus || '',
                    country: $('#filter_country_item_test').val() || 
                            $('.bootstrap-table-filter-control-country').val() || 
                            window.intendedFilters.country || '',
                    state: $('#filter_state_item').val() || 
                          $('.bootstrap-table-filter-control-state').val() || 
                          window.intendedFilters.state || '',
                    city: $('#filter_city_item').val() || 
                         $('.bootstrap-table-filter-control-city').val() || 
                         window.intendedFilters.city || '',
                    status: $('#filter').val() || window.intendedFilters.status || ''
                };
                
                // Update global intended filters
                window.intendedFilters = {
                    country: stored.country,
                    state: stored.state,
                    city: stored.city,
                    featuredStatus: stored.featuredStatus,
                    status: stored.status
                };
                
                return stored;
            }

            // Function to restore filter values (without triggering change events to prevent loops)
            function restoreFilterValues(storedValues, skipChangeEvent) {
                if (storedValues) {
                    // Only restore if value exists and is not empty
                    if (storedValues.featuredStatus && storedValues.featuredStatus.trim() !== '') {
                        const currentVal = $('#filter_featured_premium').val();
                        if (currentVal !== storedValues.featuredStatus) {
                            $('#filter_featured_premium').val(storedValues.featuredStatus);
                        }
                        $('.bootstrap-table-filter-control-featured_status').val(storedValues.featuredStatus);
                        $('select[data-field="featured_status"]').val(storedValues.featuredStatus);
                    }
                    if (storedValues.country && storedValues.country.trim() !== '') {
                        // Restore to all possible selectors (only if value is different to prevent loops)
                        const currentCountry = $('#filter_country_item_test').val();
                        if (currentCountry !== storedValues.country) {
                            $('#filter_country_item_test').val(storedValues.country).prop('selected', true);
                        }
                        $('.bootstrap-table-filter-control-country').val(storedValues.country).prop('selected', true);
                        $('select[data-field="country"]').val(storedValues.country).prop('selected', true);
                        // Also try to find Bootstrap Table's generated filter control
                        $('th[data-field="country"]').find('select').val(storedValues.country).prop('selected', true);
                    }
                    if (storedValues.state && storedValues.state.trim() !== '') {
                        const currentState = $('#filter_state_item').val();
                        if (currentState !== storedValues.state) {
                            $('#filter_state_item').val(storedValues.state).prop('selected', true);
                        }
                        $('.bootstrap-table-filter-control-state').val(storedValues.state).prop('selected', true);
                        $('select[data-field="state"]').val(storedValues.state).prop('selected', true);
                        $('th[data-field="state"]').find('select').val(storedValues.state).prop('selected', true);
                    }
                    if (storedValues.city && storedValues.city.trim() !== '') {
                        const currentCity = $('#filter_city_item').val();
                        if (currentCity !== storedValues.city) {
                            $('#filter_city_item').val(storedValues.city).prop('selected', true);
                        }
                        $('.bootstrap-table-filter-control-city').val(storedValues.city).prop('selected', true);
                        $('select[data-field="city"]').val(storedValues.city).prop('selected', true);
                        $('th[data-field="city"]').find('select').val(storedValues.city).prop('selected', true);
                    }
                    // Status is handled separately by updateStatusDropdown
                }
            }

            // Function to update status dropdown based on button mode
            function updateStatusDropdown() {
                if (typeof window.itemStatusFilterMode === 'undefined') {
                    window.itemStatusFilterMode = 'all';
                }
                const $statusDropdown = $('#filter');
                const currentValue = $statusDropdown.val();
                
                // Store all options
                const allOptions = [
                    { value: '', text: '{{ __('All') }}' },
                    { value: 'approved', text: '{{ __('Approved') }}' },
                    { value: 'review', text: '{{ __('Under Review') }}' },
                    { value: 'sold out', text: '{{ __('Sold Out') }}' },
                    { value: 'expired', text: '{{ __('Expired') }}' },
                    { value: 'inactive', text: '{{ __('Inactive') }}' },
                    { value: 'soft rejected', text: '{{ __('Soft Rejected') }}' },
                    { value: 'permanent rejected', text: '{{ __('Permanent Rejected') }}' },
                    { value: 'resubmitted', text: '{{ __('Resubmitted') }}' }
                ];
                
                if (window.itemStatusFilterMode === 'active') {
                    // Active mode: Disable dropdown and show only approved (but it's handled by button)
                    $statusDropdown.prop('disabled', true);
                    $statusDropdown.html('<option value="">{{ __('All') }}</option>');
                } else if (window.itemStatusFilterMode === 'requested') {
                    // Requested mode: Remove approved option, enable dropdown
                    $statusDropdown.prop('disabled', false);
                    let html = '<option value="">{{ __('All') }}</option>';
                    allOptions.forEach(option => {
                        if (option.value !== 'approved' && option.value !== '') {
                            html += `<option value="${option.value}">${option.text}</option>`;
                        }
                    });
                    $statusDropdown.html(html);
                    // Restore previous value if it wasn't 'approved'
                    if (currentValue && currentValue !== 'approved') {
                        $statusDropdown.val(currentValue);
                    }
                } else {
                    // All mode: Show all options, enable dropdown
                    $statusDropdown.prop('disabled', false);
                    let html = '';
                    allOptions.forEach(option => {
                        html += `<option value="${option.value}">${option.text}</option>`;
                    });
                    $statusDropdown.html(html);
                    // Restore previous value
                    if (currentValue) {
                        $statusDropdown.val(currentValue);
                    }
                }
            }

            // Active Ads - only show approved, remove status filter from dropdown
        $('#btn-active-ads').on('click', function() {
                // Store current filter values BEFORE changing mode
                const storedFilters = storeFilterValues();
                
                window.itemStatusFilterMode = 'active';
                updateButtonStates(this);
                updateStatusDropdown();
                // Clear status dropdown filter since we're using button filter
                $('#filter').val('');
                
                // Restore non-status filter values
                restoreFilterValues(storedFilters);
                
                // Get filters - read them fresh from DOM (after restoring)
                const mergedFilters = getMergedFilters();
                const filterString = mergedFilters ? JSON.stringify(mergedFilters) : JSON.stringify({ status: 'approved' });
                
            $('#table_list').bootstrapTable('refresh', {
                    query: { 
                        filter: filterString
                    }
                });
                
                // Restore filter values after refresh (Bootstrap Table might reset them)
                // Use multiple timeouts to ensure values are restored even if Bootstrap Table resets them
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 100);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 500);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 1000);
            });

            // Requested Ads - exclude approved, allow status filter from dropdown
        $('#btn-requested-ads').on('click', function() {
                // Store current filter values BEFORE changing mode
                const storedFilters = storeFilterValues();
                
                window.itemStatusFilterMode = 'requested';
                updateButtonStates(this);
                updateStatusDropdown();
                // Don't clear status dropdown - user can still filter by specific status
                
                // Restore non-status filter values
                restoreFilterValues(storedFilters);
                
                // Get filters - read them fresh from DOM (after restoring)
                const mergedFilters = getMergedFilters();
                const filterString = mergedFilters ? JSON.stringify(mergedFilters) : JSON.stringify({ status_not: 'approved' });
                
            $('#table_list').bootstrapTable('refresh', {
                    query: { 
                        filter: filterString
                    }
                });
                
                // Restore filter values after refresh (Bootstrap Table might reset them)
                // Use multiple timeouts to ensure values are restored even if Bootstrap Table resets them
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 100);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 500);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 1000);
            });

            // Show All - show all statuses, allow status filter from dropdown
        $('#btn-all-ads').on('click', function() {
                // Store current filter values BEFORE changing mode
                const storedFilters = storeFilterValues();
                
                window.itemStatusFilterMode = 'all';
                updateButtonStates(this);
                updateStatusDropdown();
                // Don't clear status dropdown - user can filter by status
                
                // Restore non-status filter values
                restoreFilterValues(storedFilters);
                
                // Get filters - read them fresh from DOM (after restoring)
                const mergedFilters = getMergedFilters();
                const filterString = mergedFilters ? JSON.stringify(mergedFilters) : '';
                
                $('#table_list').bootstrapTable('refresh', {
                    query: { 
                        filter: filterString
                    }
                });
                
                // Restore filter values after refresh (Bootstrap Table might reset them)
                // Use multiple timeouts to ensure values are restored even if Bootstrap Table resets them
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 100);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 500);
                setTimeout(function() {
                    restoreFilterValues(storedFilters);
                }, 1000);
            });

            // Helper function to refresh table with current filters
            let filterChangeTimeout = null;
            let isRefreshing = false;
            function refreshTableWithFilters() {
                // Prevent multiple simultaneous refreshes
                if (isRefreshing) {
                    return;
                }
                
                // Clear any pending timeout
                if (filterChangeTimeout) {
                    clearTimeout(filterChangeTimeout);
                }
                
                // Store current filter values before refresh
                const currentFilters = storeFilterValues();
                
                // Debounce the refresh to prevent duplicate calls
                filterChangeTimeout = setTimeout(function() {
                    isRefreshing = true;
                    
                    const mergedFilters = getMergedFilters();
                    const filterString = mergedFilters ? JSON.stringify(mergedFilters) : '';
                    
            $('#table_list').bootstrapTable('refresh', {
                        query: { 
                            filter: filterString
                        }
                    });
                    
                    // Mark as not refreshing after a delay
                    setTimeout(function() {
                        isRefreshing = false;
                    }, 1000);
                    
                    // Restore filter values after refresh (without triggering change events)
                    setTimeout(function() {
                        if (currentFilters) {
                            restoreFilterValues(currentFilters, true);
                        }
                    }, 100);
                    setTimeout(function() {
                        if (currentFilters) {
                            restoreFilterValues(currentFilters, true);
                        }
                    }, 300);
                    setTimeout(function() {
                        if (currentFilters) {
                            restoreFilterValues(currentFilters, true);
                        }
                    }, 600);
                }, 150);
            }

            // When status filter dropdown changes, update based on current mode
            $('#filter').on('change', function() {
                refreshTableWithFilters();
            });
            
            // When country filter changes, refresh table and preserve status filter mode
            // Note: custom.js will handle loading states, we just need to refresh the table
            $(document).on('change', '#filter_country_item_test, .bootstrap-table-filter-control-country, select[data-field="country"], th[data-field="country"] select', function(e) {
                // Get the selected country value from the element that triggered the event
                const selectedCountry = $(this).val() || '';
                
                // Skip if empty (user selected "All")
                if (!selectedCountry) {
                    window.intendedFilters.country = '';
                    refreshTableWithFilters();
                    return;
                }
                
                // Update global intended filter IMMEDIATELY
                window.intendedFilters.country = selectedCountry;
                
                // Clear city when country changes (states will be reloaded by custom.js)
                $('#filter_city_item').val('');
                $('.bootstrap-table-filter-control-city').val('');
                $('select[data-field="city"]').val('');
                window.intendedFilters.city = '';
                
                // Sync country value to ALL possible selectors IMMEDIATELY
                const syncCountryValue = function() {
                    if (selectedCountry) {
                        $('#filter_country_item_test').val(selectedCountry).prop('selected', true);
                        $('.bootstrap-table-filter-control-country').val(selectedCountry).prop('selected', true);
                        $('select[data-field="country"]').val(selectedCountry).prop('selected', true);
                        $('th[data-field="country"]').find('select').val(selectedCountry).prop('selected', true);
                    }
                };
                
                // Sync immediately
                syncCountryValue();
                
                // Refresh table after a short delay to allow states to load
                setTimeout(function() {
                    // Re-sync before refresh
                    syncCountryValue();
                    refreshTableWithFilters();
                    
                    // Aggressively restore country value after refresh
                    setTimeout(syncCountryValue, 50);
                    setTimeout(syncCountryValue, 150);
                    setTimeout(syncCountryValue, 300);
                    setTimeout(syncCountryValue, 500);
                    setTimeout(syncCountryValue, 1000);
                    setTimeout(syncCountryValue, 2000);
                }, 300);
            });
            
            // When state filter changes, refresh table and preserve status filter mode
            // Note: custom.js will handle loading cities, we just need to refresh the table
            $('#filter_state_item, .bootstrap-table-filter-control-state').on('change', function() {
                // Store the selected state value immediately to prevent loss
                const selectedState = $(this).val() || '';
                
                // Update global intended filter
                window.intendedFilters.state = selectedState;
                
                // Ensure state value is set in both selectors
                if (selectedState) {
                    $('#filter_state_item').val(selectedState);
                    $('.bootstrap-table-filter-control-state').val(selectedState);
                }
                
                // Refresh table after a short delay to allow cities to load
                setTimeout(function() {
                    // Re-ensure state value is still set before refresh
                    if (selectedState) {
                        $('#filter_state_item').val(selectedState);
                        $('.bootstrap-table-filter-control-state').val(selectedState);
                    }
                    refreshTableWithFilters();
                    
                    // Restore state value after refresh in case Bootstrap Table reset it
                    setTimeout(function() {
                        if (selectedState) {
                            $('#filter_state_item').val(selectedState);
                            $('.bootstrap-table-filter-control-state').val(selectedState);
                        }
                    }, 100);
                }, 300);
            });
            
            // When city filter changes, refresh table and preserve status filter mode
            $('#filter_city_item, .bootstrap-table-filter-control-city').on('change', function() {
                // Store the selected city value immediately to prevent loss
                const selectedCity = $(this).val() || '';
                
                // Update global intended filter
                window.intendedFilters.city = selectedCity;
                
                // Ensure city value is set in both selectors
                if (selectedCity) {
                    $('#filter_city_item').val(selectedCity);
                    $('.bootstrap-table-filter-control-city').val(selectedCity);
                }
                
                refreshTableWithFilters();
                
                // Restore city value after refresh in case Bootstrap Table reset it
                setTimeout(function() {
                    if (selectedCity) {
                        $('#filter_city_item').val(selectedCity);
                        $('.bootstrap-table-filter-control-city').val(selectedCity);
                    }
                }, 100);
            });
            
            // When featured/premium filter changes, refresh table and preserve status filter mode
            let featuredFilterChanging = false;
            $('#filter_featured_premium, .bootstrap-table-filter-control-featured_status, select[data-field="featured_status"]').on('change', function(e) {
                // Prevent infinite loops - if we're already processing a change, skip
                if (featuredFilterChanging) {
                    return;
                }
                
                // Get the selected featured status value
                const selectedFeatured = $(this).val() || '';
                
                // Check if value actually changed
                const currentIntended = window.intendedFilters.featuredStatus || '';
                if (selectedFeatured === currentIntended && selectedFeatured !== '') {
                    // Value hasn't changed, don't refresh
                    return;
                }
                
                // Set flag to prevent loops
                featuredFilterChanging = true;
                
                // Update global intended filter
                window.intendedFilters.featuredStatus = selectedFeatured;
                
                // Ensure featured status value is set in all selectors (without triggering change)
                if (selectedFeatured) {
                    $('#filter_featured_premium').val(selectedFeatured);
                    $('.bootstrap-table-filter-control-featured_status').val(selectedFeatured);
                    $('select[data-field="featured_status"]').val(selectedFeatured);
                }
                
                // Refresh table
                refreshTableWithFilters();
                
                // Clear flag after a delay
                setTimeout(function() {
                    featuredFilterChanging = false;
                }, 500);
            });
            
            // Initialize status dropdown on page load
            updateStatusDropdown();
            
            // Listen to Bootstrap Table refresh events to restore filter values
            $('#table_list').on('refresh.bs.table', function() {
                // Restore all intended filter values after Bootstrap Table refreshes (without triggering change events)
                if (window.intendedFilters) {
                    setTimeout(function() {
                        restoreFilterValues(window.intendedFilters, true);
                    }, 50);
                    setTimeout(function() {
                        restoreFilterValues(window.intendedFilters, true);
                    }, 200);
                    setTimeout(function() {
                        restoreFilterValues(window.intendedFilters, true);
                    }, 500);
                }
            });
            
            // Also listen to post-body event (after table body is updated)
            $('#table_list').on('post-body.bs.table', function() {
                if (window.intendedFilters) {
                    restoreFilterValues(window.intendedFilters, true);
                }
        });
});
    </script>
@endsection
