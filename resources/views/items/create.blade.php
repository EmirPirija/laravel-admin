@extends('layouts.main')

@section('title')
    {{ __('Create Advertisement') }}
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
        @php
            $hasAdminCredentials = !empty($adminUserEmail) && !empty($adminUserPassword);
        @endphp

        @if (!$hasAdminCredentials)
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fa fa-exclamation-triangle"></i>
                <strong>{{ __('Warning') }}:</strong>
                {{ __('Please add email and password in General Settings first to create advertisements on behalf of users.') }}
                <a href="{{ route('settings.system', 'system') }}" class="alert-link">{{ __('Go to Settings') }}</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('advertisement.store') }}" data-parsley-validate
                    enctype="multipart/form-data">
                    @csrf

                    <ul class="nav nav-tabs" id="addItemTabs" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#categories"
                                data-tab-index="0">{{ __('Categories') }}</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#listing"
                                data-tab-index="2">{{ __('Listing Details') }}</a></li>
                        <li class="nav-item" id="custom-tab-item" style="display: none;"><a class="nav-link"
                                data-bs-toggle="tab" href="#custom" data-tab-index="3">{{ __('Other Details') }}</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#images"
                                data-tab-index="4">{{ __('Product Images') }}</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#address"
                                data-tab-index="5">{{ __('Address') }}</a></li>
                    </ul>

                    <div class="tab-content pt-3">

                        {{-- Listing Details --}}
                        <div class="tab-pane fade" id="listing">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label>{{ __('Name') }} <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name-input" value="{{ old('name') }}"
                                        class="form-control" required>
                                </div>
                                <div class="col-6 mb-3">
                                    <label>{{ __('Slug') }}</label>
                                    <input type="text" name="slug" value="{{ old('slug') }}" class="form-control"
                                        placeholder="auto-generated if blank">
                                </div>

                                <div class="col-6 mb-3" id="price-field">
                                    <label>{{ __('Price') }} <span class="text-danger">*</span></label>
                                    <input type="number" name="price" id="price-input" value="{{ old('price') }}"
                                        class="form-control" required>
                                </div>

                                <div class="col-6 mb-3" id="salary-fields" style="display: none;">
                                    <label>{{ __('Min Salary') }}</label>
                                    <input type="number" name="min_salary" id="min-salary-input" class="form-control mb-2"
                                        value="{{ old('min_salary') }}">
                                    <label>{{ __('Max Salary') }}</label>
                                    <input type="number" name="max_salary" id="max-salary-input" class="form-control"
                                        value="{{ old('max_salary') }}">
                                </div>

                                <div class="col-6 mb-3">
                                    <label>{{ __('Phone Number') }}</label>
                                    <input type="number" name="contact" id="contact-input"
                                        value="{{ old('contact', auth()->user()->phone ?? '') }}" class="form-control"
                                        required>
                                </div>

                                <div class="col-12 mb-3">
                                    <label>{{ __('Description') }} <span class="text-danger">*</span></label>
                                    <textarea name="description" id="description-input" class="form-control" required>{{ old('description') }}</textarea>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary btn-prev-tab"
                                    data-prev-tab="categories">{{ __('Previous') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab"
                                    data-next-tab="custom-or-images">{{ __('Next') }}</button>
                            </div>
                        </div>

                        {{-- Categories Tab --}}
                        <div class="tab-pane fade show active" id="categories">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="row w-100">
                                    <div class="col-md-6 mb-3">
                                        <div class="flex-grow-1">
                                            <label class="form-label mb-0">{{ __('Selected Category') }}</label>
                                            <input type="text" id="selected-category-display" class="form-control"
                                                readonly placeholder="{{ __('No category selected') }}" required>
                                            <input type="hidden" name="category_id" id="category_id" value=""
                                                required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div id="breadcrumb-container" style="display: none;">
                                            <nav aria-label="breadcrumb">
                                                <ol class="breadcrumb mb-0" id="category-breadcrumb"></ol>
                                            </nav>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">
                                    <div id="categories-container">
                                        <label class="form-label mb-3">{{ __('Select Category') }}</label>
                                        <div id="categories-list" class="row"></div>
                                        <div id="load-more-container" class="text-center mt-3" style="display: none;">
                                            <button type="button" class="btn btn-primary" id="load-more-categories">
                                                {{ __('Load More') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 text-end">
                                <button type="button" class="btn btn-primary btn-next-tab"
                                    data-next-tab="listing">{{ __('Next') }}</button>
                            </div>
                        </div>


                        {{-- Other Details - Custom Fields --}}
                        <div class="tab-pane fade" id="custom">
                            <div class="text-muted">{{ __('Select a category to load custom fields.') }}</div>
                        </div>

                        {{-- Product Images --}}
                        <div class="tab-pane fade" id="images">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label>{{ __('Main Image') }} <span class="text-danger">*</span></label>
                                    <input type="file" name="image" id="image-input" class="form-control" required
                                        accept="image/*">
                                </div>
                                <div class="col-12 mb-3">
                                    <label>{{ __('Other Images') }}</label>
                                    <input type="file" name="gallery_images[]" class="form-control" multiple
                                        accept="image/*">
                                    <small class="text-muted">{{ __('You can select multiple images at once') }}</small>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="video_link">{{ __('Video Link') }}</label>
                                    <input type="url" name="video_link" id="video_link" class="form-control"
                                        value="{{ old('video_link') }}"
                                        placeholder="https://www.youtube.com/watch?v=...">
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary btn-prev-tab"
                                    data-prev-tab="custom-or-images">{{ __('Previous') }}</button>
                                <button type="button" class="btn btn-primary btn-next-tab"
                                    data-next-tab="address">{{ __('Next') }}</button>
                            </div>
                        </div>

                        {{-- Address --}}
                        <div class="tab-pane fade" id="address">
                            <div class="row">
                                <div class="col-12">
                                    <div class="form-group mb-3">
                                        <label class="form-label">{{ __('Selected Address') }} <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="address-input" readonly
                                            placeholder="{{ __('Select a location on the map to see the address') }}"
                                            required>
                                    </div>

                                    <label class="form-label">{{ __('Select Location on Map') }} <span
                                            class="text-danger">*</span></label>
                                    <div id="map"
                                        style="height: 500px; border: 1px solid #ddd; border-radius: 5px;"></div>

                                    <!-- Hidden inputs for form submission -->
                                    <input type="hidden" id="latitude-input" name="latitude" required />
                                    <input type="hidden" id="longitude-input" name="longitude" required />
                                    <input type="hidden" name="country_input" id="country-input">
                                    <input type="hidden" name="state_input" id="state-input">
                                    <input type="hidden" name="city_input" id="city-input">
                                    <input type="hidden" name="address" id="address-hidden">
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary btn-prev-tab"
                                    data-prev-tab="images">{{ __('Previous') }}</button>
                                <button type="submit" class="btn btn-primary">{{ __('Post') }}</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        $(document).ready(function() {
            let selectedCategoryId = null;
            let selectedCategoryName = '';
            let currentParentId = null;
            let currentPage = 1;
            let hasMoreCategories = true;
            let categoryHistory = []; // Track navigation history

            // Load initial parent categories when categories tab is shown
            $('#addItemTabs a[href="#categories"]').on('shown.bs.tab', function() {
                if ($('#categories-list').is(':empty')) {
                    loadCategories(null, 1);
                }
            });

            // Load categories immediately if categories tab is already active on page load
            // Check if categories tab is visible/active
            if (($('#categories').hasClass('active') || $('#categories').hasClass('show')) && $('#categories-list')
                .is(':empty')) {
                loadCategories(null, 1);
            }

            // Fallback: Load categories after a short delay if still empty (for edge cases)
            setTimeout(function() {
                if ($('#categories-list').is(':empty') && ($('#categories').is(':visible') || $(
                        '#categories').hasClass('active'))) {
                    loadCategories(null, 1);
                }
            }, 100);

            // Load categories function
            function loadCategories(parentId, page = 1) {
                const url = parentId ?
                    '{{ route('advertisement.get-subcategories') }}' :
                    '{{ route('advertisement.get-parent-categories') }}';

                $.ajax({
                    url: url,
                    type: 'GET',
                    data: {
                        category_id: parentId,
                        page: page,
                        per_page: 10
                    },
                    success: function(response) {
                        if (page === 1) {
                            $('#categories-list').html('');
                        }

                        if (response.data && response.data.length > 0) {
                            let html = '';
                            response.data.forEach(function(category) {
                                const hasSubcategories = category.subcategories_count > 0;
                                const categoryImage = category.image ||
                                    '/assets/images/default-category.png';

                                html += `
                            <div class="col-md-2 col-lg-2 col-xl-2 mb-3">
                                <div class="card category-card h-100" 
                                     data-category-id="${category.id}" 
                                     data-category-name="${category.name}"
                                     data-has-subcategories="${hasSubcategories}"
                                     style="cursor: pointer; border: 2px solid #e0e0e0; transition: all 0.3s;">
                                    <div class="card-body p-2 text-center">
                                        <img src="${categoryImage}" alt="${category.name}" class="img-fluid mb-2" style="max-height: 60px; width: auto; border-radius: 5px;">
                                        <strong class="d-block" style="font-size: 12px;">${category.name}</strong>
                                        ${hasSubcategories ? '<small class="text-muted" style="font-size: 10px;"><i class="fas fa-chevron-right"></i> Subcategories</small>' : '<small class="text-success" style="font-size: 10px;"><i class="fas fa-check"></i> Select</small>'}
                                    </div>
                                </div>
                            </div>
                        `;
                            });
                            $('#categories-list').append(html);

                            // Add click handlers
                            $('.category-card').off('click').on('click', function() {
                                const categoryId = $(this).data('category-id');
                                const categoryName = $(this).data('category-name');
                                const hasSubcategories = $(this).data('has-subcategories') == 1;

                                // Visual feedback - remove previous selections
                                $('.category-card').removeClass('border-primary').css({
                                    'border-color': '#e0e0e0',
                                    'background-color': ''
                                });

                                // Highlight selected
                                $(this).addClass('border-primary').css({
                                    'border-color': '#007bff',
                                    'background-color': '#f0f8ff'
                                });

                                if (hasSubcategories) {
                                    // Navigate to subcategories
                                    categoryHistory.push({
                                        parentId: currentParentId,
                                        page: currentPage,
                                        html: $('#categories-list').html(),
                                        loadMoreVisible: $('#load-more-container').is(
                                            ':visible')
                                    });
                                    currentParentId = categoryId;
                                    currentPage = 1;
                                    hasMoreCategories = true;
                                    updateBreadcrumb(categoryName, categoryId);
                                    loadCategories(categoryId, 1);
                                } else {
                                    // Select this category (no subcategories)
                                    selectCategory(categoryId, categoryName);
                                }
                            });

                            // Show/hide load more button
                            hasMoreCategories = response.has_more || false;
                            if (hasMoreCategories && page === 1) {
                                $('#load-more-container').show();
                            } else if (!hasMoreCategories) {
                                $('#load-more-container').hide();
                            }

                            currentPage = page;
                        } else {
                            if (page === 1) {
                                $('#categories-list').html(
                                    '<div class="col-12"><p class="text-muted text-center">No categories found.</p></div>'
                                    );
                            }
                            $('#load-more-container').hide();
                            hasMoreCategories = false;
                        }
                    },
                    error: function() {
                        $('#categories-list').html(
                            '<div class="col-12"><p class="text-danger text-center">Error loading categories.</p></div>'
                            );
                    }
                });
            }

            // Load more categories
            $('#load-more-categories').on('click', function() {
                if (hasMoreCategories) {
                    loadCategories(currentParentId, currentPage + 1);
                }
            });

            // Select category function
            function selectCategory(categoryId, categoryName) {
                selectedCategoryId = categoryId;
                selectedCategoryName = categoryName;
                $('#category_id').val(categoryId);
                $('#selected-category-display').val(categoryName);

                // Load custom fields in the "Other Details" tab
                loadCustomFields(categoryId);

                // Show success message
                showCategorySelectedMessage(categoryName);
            }

            // Update breadcrumb
            function updateBreadcrumb(categoryName, categoryId) {
                $('#breadcrumb-container').show();
                let breadcrumbHtml =
                    '<li class="breadcrumb-item"><a href="javascript:void(0)" class="breadcrumb-link" data-parent-id="null">Home</a></li>';

                // Add current category to breadcrumb
                breadcrumbHtml += `<li class="breadcrumb-item active">${categoryName}</li>`;

                $('#category-breadcrumb').html(breadcrumbHtml);

                // Add click handler for breadcrumb
                $('.breadcrumb-link').off('click').on('click', function() {
                    const parentId = $(this).data('parent-id') === 'null' ? null : $(this).data(
                    'parent-id');
                    goBackToCategory(parentId);
                });
            }

            // Go back to previous category level
            function goBackToCategory(parentId) {
                if (categoryHistory.length > 0) {
                    const previous = categoryHistory.pop();
                    currentParentId = previous.parentId;
                    currentPage = previous.page;
                    $('#categories-list').html(previous.html);

                    if (previous.loadMoreVisible) {
                        $('#load-more-container').show();
                    } else {
                        $('#load-more-container').hide();
                    }

                    if (currentParentId === null) {
                        $('#breadcrumb-container').hide();
                    } else {
                        // Update breadcrumb
                        updateBreadcrumbForBack();
                    }

                    // Re-attach click handlers
                    $('.category-card').off('click').on('click', function() {
                        const categoryId = $(this).data('category-id');
                        const categoryName = $(this).data('category-name');
                        const hasSubcategories = $(this).data('has-subcategories') == 1;

                        $('.category-card').removeClass('border-primary').css({
                            'border-color': '#e0e0e0',
                            'background-color': ''
                        });
                        $(this).addClass('border-primary').css({
                            'border-color': '#007bff',
                            'background-color': '#f0f8ff'
                        });

                        if (hasSubcategories) {
                            categoryHistory.push({
                                parentId: currentParentId,
                                page: currentPage,
                                html: $('#categories-list').html(),
                                loadMoreVisible: $('#load-more-container').is(':visible')
                            });
                            currentParentId = categoryId;
                            currentPage = 1;
                            hasMoreCategories = true;
                            updateBreadcrumb(categoryName, categoryId);
                            loadCategories(categoryId, 1);
                        } else {
                            selectCategory(categoryId, categoryName);
                        }
                    });
                } else {
                    // Go to root
                    currentParentId = null;
                    currentPage = 1;
                    categoryHistory = [];
                    $('#breadcrumb-container').hide();
                    loadCategories(null, 1);
                }
            }

            function updateBreadcrumbForBack() {
                // Simplified breadcrumb for back navigation
                $('#breadcrumb-container').show();
                let breadcrumbHtml =
                    '<li class="breadcrumb-item"><a href="javascript:void(0)" class="breadcrumb-link" data-parent-id="null">Home</a></li>';
                $('#category-breadcrumb').html(breadcrumbHtml);

                $('.breadcrumb-link').off('click').on('click', function() {
                    goBackToCategory(null);
                });
            }

            // Show category selected message
            function showCategorySelectedMessage(categoryName) {
                const alertHtml = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Category Selected:</strong> ${categoryName}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
                $('#categories-container').prepend(alertHtml);
                setTimeout(function() {
                    $('.alert').fadeOut();
                }, 3000);
            }

            // Function to load custom fields in the "Other Details" tab
            function loadCustomFields(categoryId) {
                if (!categoryId) {
                    $('#custom').html(
                        '<div class="text-muted">{{ __('Select a category to load custom fields.') }}</div>');
                    $('#custom-tab-item').hide();
                    return;
                }

                $.ajax({
                    url: `/get-custom-fields/${categoryId}`,
                    type: 'GET',
                    success: function(response) {
                        let html = '';
                        html += `<div class="row">`;

                        if (response.fields.length === 0) {
                            html += `<p class="text-muted">No custom fields for this category.</p>`;
                            html += `<div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary btn-prev-tab" data-prev-tab="listing">{{ __('Previous') }}</button>
                        <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="images">{{ __('Next') }}</button>
                    </div>`;
                            $('#custom-tab-item').hide();
                            hasCustomFields = false;
                            // Ensure custom tab link is disabled
                            $('[href="#custom"]').css('pointer-events', 'none').css('cursor',
                                'not-allowed');
                        } else {
                            $('#custom-tab-item').show();
                            hasCustomFields = true;
                            // Ensure custom tab link is disabled (navigation only via buttons)
                            $('[href="#custom"]').css('pointer-events', 'none').css('cursor',
                                'not-allowed');
                            response.fields.forEach(function(field) {
                                const isRequired = field.required ? 'required' : '';
                                html += `<div class="col-md-6 mb-3">`;
                                html +=
                                    `<label>${field.name}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

                                if (field.type === 'textbox') {
                                    html +=
                                        `<input type="text" name="custom_fields[${field.id}]" class="form-control custom-field-input" ${isRequired}>`;
                                } else if (field.type === 'number') {
                                    html +=
                                        `<input type="number" name="custom_fields[${field.id}]" class="form-control custom-field-input" ${isRequired}>`;
                                } else if (field.type === 'fileinput') {
                                    html +=
                                        `<input type="file" name="custom_field_files[${field.id}]" class="form-control custom-field-input" ${isRequired}>`;
                                } else if (field.type === 'dropdown' || field.type ===
                                    'radio') {
                                    const options = Array.isArray(field.values) ? field.values :
                                        JSON.parse(field.values ?? '[]');
                                    html +=
                                        `<select name="custom_fields[${field.id}]" class="form-select custom-field-input" ${isRequired}>`;
                                    html += `<option value="">Select</option>`;
                                    options.forEach(option => {
                                        html +=
                                            `<option value="${option}">${option}</option>`;
                                    });
                                    html += `</select>`;
                                } else if (field.type === 'checkbox') {
                                    const options = Array.isArray(field.values) ? field.values :
                                        JSON.parse(field.values ?? '[]');
                                    options.forEach(option => {
                                        html += `
                                    <div class="form-check">
                                        <input class="form-check-input custom-field-checkbox" type="checkbox" name="custom_fields[${field.id}][]" value="${option}" ${isRequired}>
                                        <label class="form-check-label">${option}</label>
                                    </div>
                                `;
                                    });
                                }

                                html += `</div>`;
                            });

                            // Add navigation buttons for custom fields
                            html += `<div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary btn-prev-tab" data-prev-tab="listing">{{ __('Previous') }}</button>
                        <button type="button" class="btn btn-primary btn-next-tab" data-next-tab="images">{{ __('Next') }}</button>
                    </div>`;
                        }

                        html += `</div>`;
                        $('#custom').html(html);
                        updateCustomFieldsStatus();

                        if (response.is_job_category || response.price_optional) {
                            $('#price-field').hide();
                            $('#price-input').removeAttr('required');
                            $('#salary-fields').show();
                        } else {
                            $('#price-field').show();
                            $('#price-input').attr('required', 'required');
                            $('#salary-fields').hide();
                        }
                    },
                    error: function() {
                        $('#custom').html(
                        '<div class="text-danger">Error loading custom fields.</div>');
                        $('#custom-tab-item').hide();
                    }
                });
            }

            // Tab navigation with validation
            let hasCustomFields = false;
            let navigationAllowed = false; // Flag to track if navigation is from button

            // Update hasCustomFields when custom fields are loaded
            function updateCustomFieldsStatus() {
                hasCustomFields = $('#custom .custom-field-input').length > 0;
            }

            // Disable all tab links except categories (first tab)
            $('#addItemTabs a[data-bs-toggle="tab"]').each(function() {
                const href = $(this).attr('href');
                if (href !== '#categories') {
                    $(this).css('pointer-events', 'none').css('cursor', 'not-allowed');
                }
            });

            // Prevent direct tab clicking - use Next/Previous buttons
            $(document).on('click', '#addItemTabs a[data-bs-toggle="tab"]', function(e) {
                const targetTab = $(this).attr('href');
                const currentTab = $('.tab-pane.active').attr('id');

                // Allow clicking on categories tab (first tab) only if we're already on it
                if (targetTab === '#categories' && currentTab === 'categories') {
                    return true;
                }

                // If navigation is not allowed, prevent it
                if (!navigationAllowed) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    showErrorToast('Please use the Next/Previous buttons to navigate between tabs.');
                    return false;
                }
            });

            // Also prevent Bootstrap tab event
            $('#addItemTabs a[data-bs-toggle="tab"]').on('show.bs.tab', function(e) {
                const targetTab = $(e.target).attr('href');
                const currentTab = $('.tab-pane.active').attr('id');

                // Only allow if we're going to categories and already on it
                if (targetTab === '#categories' && currentTab === 'categories') {
                    return true;
                }

                // If navigation is not allowed, prevent it
                if (!navigationAllowed) {
                    e.preventDefault();
                    showErrorToast('Please use the Next/Previous buttons to navigate between tabs.');
                    return false;
                }
            });

            // Next button handler
            $(document).on('click', '.btn-next-tab', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const nextTab = $(this).data('next-tab');
                const currentTab = $('.tab-pane.active').attr('id');

                // Validate current tab before moving
                if (!validateCurrentTab(currentTab)) {
                    return false;
                }

                // Enable navigation temporarily
                navigationAllowed = true;

                // Re-enable the target tab link temporarily
                let targetTabLink = null;
                if (nextTab === 'custom-or-images') {
                    targetTabLink = hasCustomFields ? $('[href="#custom"]') : $('[href="#images"]');
                } else {
                    targetTabLink = $('[href="#' + nextTab + '"]');
                }

                if (targetTabLink.length) {
                    targetTabLink.css('pointer-events', 'auto').css('cursor', 'pointer');
                }

                // Handle special case: custom-or-images
                if (nextTab === 'custom-or-images') {
                    if (hasCustomFields) {
                        $('[href="#custom"]').tab('show');
                    } else {
                        $('[href="#images"]').tab('show');
                    }
                } else {
                    $('[href="#' + nextTab + '"]').tab('show');

                    // Initialize map if navigating to address tab
                    if (nextTab === 'address') {
                        setTimeout(() => {
                            initMap();
                        }, 300);
                    }
                }

                // Disable navigation and tab links after tab switch
                setTimeout(() => {
                    navigationAllowed = false;
                    if (targetTabLink && targetTabLink.length) {
                        targetTabLink.css('pointer-events', 'none').css('cursor', 'not-allowed');
                    }
                }, 200);
            });

            // Previous button handler
            $(document).on('click', '.btn-prev-tab', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const prevTab = $(this).data('prev-tab');

                // Enable navigation temporarily
                navigationAllowed = true;

                // Re-enable the target tab link temporarily
                let targetTabLink = null;
                if (prevTab === 'custom-or-images') {
                    targetTabLink = hasCustomFields ? $('[href="#custom"]') : $('[href="#listing"]');
                } else {
                    targetTabLink = $('[href="#' + prevTab + '"]');
                }

                if (targetTabLink && targetTabLink.length) {
                    targetTabLink.css('pointer-events', 'auto').css('cursor', 'pointer');
                }

                // Handle special case: custom-or-images
                if (prevTab === 'custom-or-images') {
                    if (hasCustomFields) {
                        $('[href="#custom"]').tab('show');
                    } else {
                        $('[href="#listing"]').tab('show');
                    }
                } else {
                    $('[href="#' + prevTab + '"]').tab('show');
                }

                // Disable navigation and tab links after tab switch
                setTimeout(() => {
                    navigationAllowed = false;
                    if (targetTabLink && targetTabLink.length) {
                        targetTabLink.css('pointer-events', 'none').css('cursor', 'not-allowed');
                    }
                }, 200);
            });

            // Validation function for current tab
            function validateCurrentTab(tabId) {
                let isValid = true;
                let firstInvalidField = null;

                if (tabId === 'categories') {
                    if (!selectedCategoryId || !$('#category_id').val()) {
                        showErrorToast('Please select a category first.');
                        isValid = false;
                    }
                } else if (tabId === 'listing') {
                    const name = $('#name-input').val().trim();
                    const description = $('#description-input').val().trim();
                    const price = $('#price-input').val();
                    const minSalary = $('#min-salary-input').val();
                    const maxSalary = $('#max-salary-input').val();
                    const contact = $('#contact-input').val().trim();

                    if (!name) {
                        showErrorToast('Please enter a name.');
                        $('#name-input').focus();
                        isValid = false;
                    } else if (!description) {
                        showErrorToast('Please enter a description.');
                        $('#description-input').focus();
                        isValid = false;
                    } else if ($('#price-field').is(':visible') && !price) {
                        showErrorToast('Please enter a price.');
                        $('#price-input').focus();
                        isValid = false;
                    } else if ($('#salary-fields').is(':visible')) {
                        if (minSalary && maxSalary && parseFloat(minSalary) > parseFloat(maxSalary)) {
                            showErrorToast('Min salary cannot be greater than max salary.');
                            $('#min-salary-input').focus();
                            isValid = false;
                        }
                    }
                    
                    // Always validate contact field
                    if (isValid && !contact) {
                        showErrorToast('Please enter a Phone Number.');
                        $('#contact-input').focus();
                        isValid = false;
                    }
                } else if (tabId === 'custom') {
                    // Validate required custom fields
                    let firstInvalidField = null;
                    $('#custom .custom-field-input[required]').each(function() {
                        const $field = $(this);
                        if ($field.is(':file')) {
                            if (!$field[0].files || $field[0].files.length === 0) {
                                if (!firstInvalidField) {
                                    firstInvalidField = $field;
                                }
                                isValid = false;
                                return false;
                            }
                        } else if ($field.is('select')) {
                            if (!$field.val()) {
                                if (!firstInvalidField) {
                                    firstInvalidField = $field;
                                }
                                isValid = false;
                                return false;
                            }
                        } else if (!$field.val().trim()) {
                            if (!firstInvalidField) {
                                firstInvalidField = $field;
                            }
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid && firstInvalidField) {
                        showErrorToast('Please fill all required custom fields.');
                        firstInvalidField.focus();
                    }

                    // Validate checkbox groups
                    $('.custom-field-checkbox[required]').each(function() {
                        const name = $(this).attr('name');
                        if (!$('input[name="' + name + '"]:checked').length) {
                            showErrorToast(
                                'Please select at least one option for required checkbox fields.');
                            isValid = false;
                            return false;
                        }
                    });
                } else if (tabId === 'images') {
                    const imageInput = $('#image-input')[0];
                    if (!imageInput || !imageInput.files || imageInput.files.length === 0) {
                        showErrorToast('Please select a main image.');
                        $('#image-input').focus();
                        isValid = false;
                    }
                } else if (tabId === 'address') {
                    const lat = $('#latitude-input').val();
                    const lng = $('#longitude-input').val();
                    const address = $('#address-input').val();  

                    if (!lat || !lng) {
                        showErrorToast('Please select a location on the map.');
                        isValid = false;
                    } else if (!address) {
                        showErrorToast('Please select a location on the map to get the address.');
                        isValid = false;
                    }
                }

                return isValid;
            }

            // Form validation before submit
            $('form').on('submit', function(e) {
                // Validate all tabs before submit
                const tabs = ['categories', 'listing', 'images', 'address'];
                if (hasCustomFields) {
                    tabs.splice(2, 0, 'custom');
                }

                for (let i = 0; i < tabs.length; i++) {
                    if (!validateCurrentTab(tabs[i])) {
                        e.preventDefault();
                        $('[href="#' + tabs[i] + '"]').tab('show');
                        return false;
                    }
                }
            });

            // Email validation helper
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
            }

            // Toggle password visibility for user details
            // $('#toggle-user-password-visibility').on('click', function() {
            //     const passwordInput = $('#user-password-input');
            //     const eyeIcon = $('#user-password-eye-icon');

            //     if (passwordInput.attr('type') === 'password') {
            //         passwordInput.attr('type', 'text');
            //         eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
            //     } else {
            //         passwordInput.attr('type', 'password');
            //         eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
            //     }
            // });
        });

        // Country/State/City dropdown handlers - kept for map address population
        $(document).ready(function() {
            // These handlers are now only used for populating dropdowns from map selection
            // No manual dropdowns are shown in the UI anymore
        });

        // Map initialization
        let map, marker;
        let mapInitialized = false;

        function initMap() {
            // Check if map is already initialized
            if (mapInitialized && map) {
                // Invalidate size in case container was hidden
                setTimeout(() => {
                    map.invalidateSize();
                }, 100);
                return;
            }

            // Check if map container exists
            const mapContainer = document.getElementById('map');
            if (!mapContainer) {
                console.error('Map container not found');
                return;
            }

            const defaultLat = 20.5937;
            const defaultLng = 78.9629;

            try {
                map = L.map('map').setView([defaultLat, defaultLng], 5);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(map);

                marker = L.marker([defaultLat, defaultLng], {
                    draggable: true
                }).addTo(map);

                updateLatLngInputs(defaultLat, defaultLng);

                marker.on('dragend', function(e) {
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

                map.on('geosearch/showlocation', function(result) {
                    const {
                        x: lng,
                        y: lat,
                        label
                    } = result.location;
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 15);
                    updateLatLngInputs(lat, lng);
                    document.getElementById("address-input").value = label;
                    document.getElementById("address-hidden").value = label;
                    fetchAddressFromCoords(lat, lng);
                });

                // Also handle map click to update location
                map.on('click', function(e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    marker.setLatLng([lat, lng]);
                    updateLatLngInputs(lat, lng);
                    fetchAddressFromCoords(lat, lng);
                });

                mapInitialized = true;
            } catch (error) {
                console.error('Error initializing map:', error);
            }
        }

        function updateLatLngInputs(lat, lng) {
            document.getElementById("latitude-input").value = lat;
            document.getElementById("longitude-input").value = lng;
        }

        function fetchAddressFromCoords(lat, lng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`)
                .then(res => res.json())
                .then(data => {
                    if (!data || !data.address) {
                        console.error('Invalid address data received');
                        return;
                    }

                    const address = data.address;
                    const fullAddress = data.display_name || '';

                    // Update address display
                    document.getElementById("address-input").value = fullAddress;
                    document.getElementById("address-hidden").value = fullAddress;

                    // Extract address components from OpenStreetMap response structure
                    const countryName = address.country || '';
                    const stateName = address.state || address.state_district || '';
                    const cityName = address.city || address.town || address.village || address.county || address
                        .state_district || '';

                    // Update hidden inputs for form submission
                    document.getElementById("country-input").value = countryName;
                    document.getElementById("state-input").value = stateName;
                    document.getElementById("city-input").value = cityName;
                })
                .catch(error => {
                    console.error('Error fetching address:', error);
                });
        }

        // Initialize map when address tab is shown
        $(document).ready(function() {
            // Listen for when address tab is shown
            $('a[href="#address"]').on('shown.bs.tab', function() {
                setTimeout(() => {
                    initMap();
                }, 300);
            });

            // Also check if address tab is already active on page load
            if ($('#address').hasClass('active') || $('#address').hasClass('show')) {
                setTimeout(() => {
                    initMap();
                }, 500);
            }
        });
    </script>
@endsection
