@extends('layouts.main')

@section('title')
    {{ __('Users') }}
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
                <div class="row">
                    <div class="col-12">
                        <table class="table-borderless table-striped" aria-describedby="mydesc" id="table_list"
                               data-toggle="table" data-url="{{ route('customer.show',1) }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true"
                               data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                               data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                               data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false"
                               data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-escape="true"
                               data-pagination-successively-size="3" data-query-params="queryParams" data-table="users" data-status-column="deleted_at"
                               data-show-export="true" data-export-options='{"fileName": "customer-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                               data-mobile-responsive="true">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                <th scope="col" data-field="profile" data-formatter="imageFormatter">{{ __('Profile') }}</th>
                                <th scope="col" data-field="name" data-sortable="true">{{ __('Name') }}</th>
                                <th scope="col" data-field="email" data-sortable="true">{{ __('Email') }}</th>
                                <th scope="col" data-field="mobile" data-sortable="true">{{ __('Mobile') }}</th>
                                <th scope="col" data-field="type" data-sortable="true">{{ __('Type') }}</th>
                                <th scope="col" data-field="address" data-sortable="true">{{ __('Address') }}</th>
                                <th scope="col" data-field="items_count" data-sortable="true">{{ __('Total Post') }}</th>
                                <th scope="col" data-field="status" data-formatter="statusSwitchFormatter" data-sortable="false">{{ __('Status') }}</th>
                                 @can('customer-update')
                                  <th scope="col" data-field="auto_approve_advertisement" data-formatter="autoApproveItemSwitchFormatter" data-sortable="false">{{ __('Auto Approve Advertisement') }}</th>
                                @endcan
                                <th scope="col" data-field="operate" data-escape="false" data-align="center" data-sortable="false" data-events="userEvents">{{ __('Action') }}</th>
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="assignPackageModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
             aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Assign Packages') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="resetModal()"></button>
                    </div>
                    <div class="modal-body">
                        <form class="create-form" action="{{ route('customer.assign.package') }}" method="POST" data-parsley-validate data-success-function="assignApprovalSuccess">
                            @csrf
                            <input type="hidden" name="user_id" id='user_id'>
                            <div id="currency-settings" data-symbol="{{ $currency_symbol }}"  data-position="{{ $currency_symbol_position }}" data-free-ad-listing="{{ $free_ad_listing }}"></div>
                            @if($free_ad_listing != 1)
                            <div class="form-group row select-package">
                                <div class="col-md-6">
                                    <input type="radio" id="item_package" class="package_type form-check-input" name="package_type" value="item_listing" required>
                                    <label for="item_package">{{ __('Item Listing Package') }}</label>
                                </div>
                                <div class="col-md-6">
                                    <input type="radio" id="advertisement_package" class="package_type form-check-input" name="package_type" value="advertisement" required>
                                    <label for="advertisement_package">{{ __('Advertisement Package') }}</label>
                                </div>
                            </div>
                            @endif
                            <div class="row mt-3" id="item-listing-package-div" style="display: none;">
                                <div class="form-group col-md-12">
                                    <label for="package">{{__("Select Item Listing Package")}}</label>
                                    <select name="package_id" class="form-select package" id="item-listing-package" aria-label="Package">
                                        <option value="" disabled selected>{{__("Select Option")}}</option>
                                        @foreach($itemListingPackage as $package)
                                            <option value="{{$package->id}}" data-details="{{json_encode($package)}}">{{$package->name}}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="row mt-3" id="advertisement-package-div" style="{{ $free_ad_listing == '1' ? 'display: block;' : 'display: none;' }}">
                                <div class="form-group col-md-12">
                                    <label for="package">{{__("Select Advertisement Package")}}</label>
                                    <select name="package_id" class="form-select package" id="advertisement-package" aria-label="Package">
                                        <option value="" disabled selected>{{__("Select Option")}}</option>
                                        @foreach($advertisementPackage as $package)
                                            <option value="{{$package->id}}" data-details="{{json_encode($package)}}">{{$package->name}}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div id="package_details" class="mt-3" style="display: none;">
                                <p><strong>{{__("Name")}}:</strong> <span id="package_name"></span></p>
                                <p><strong>{{__("Price")}}:</strong> <span id="package_price"></span></p>
                                <p><strong>{{__("Final Price")}}:</strong> <span id="package_final_price"></span></p>
                                <p><strong>{{__("Limitation")}}:</strong> <span id="package_duration"></span></p>
                            </div>
                            <div class="form-group row payment" style="display: none">
                                <div class="col-md-6">
                                    <input type="radio" id="cash_payment" class="payment_gateway form-check-input" name="payment_gateway" value="cash" required>
                                    <label for="cash_payment">{{ __('Cash') }}</label>
                                </div>
                                <div class="col-md-6">
                                    <input type="radio" id="cheque_payment" class="payment_gateway form-check-input" name="payment_gateway" value="cheque" required>
                                    <label for="cheque_payment">{{ __('Cheque') }}</label>
                                </div>
                            </div>
                            <div class="form-group cheque mt-3" style="display: none">
                                <label for="cheque">{{ __('Add cheque number') }}</label>
                                <input type="text" id="cheque" class="form-control" name="cheque_number" data-parsley-required="true">
                            </div>
                            <input type="submit" value="{{__("Save")}}" class="btn btn-primary mt-3">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manage Packages Modal (View Active Packages Only) -->
        <div id="managePackagesModal" class="modal fade modal-lg" tabindex="-1" role="dialog" aria-labelledby="managePackagesModalLabel"
             aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="managePackagesModalLabel">{{ __('Active Packages') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="resetManagePackagesModal()"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="manage_user_id" name="user_id">
                        
                        <div id="active-packages-list">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">{{ __('Loading...') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                        <!-- <button type="button" class="btn btn-primary" onclick="refreshManagePackages()">{{ __('Refresh') }}</button> -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancel Subscription Modal -->
        <div id="cancelSubscriptionModal" class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Cancel Subscription') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="cancel-subscription-form" class="create-form" action="{{ route('customer.cancel.package') }}" method="POST" data-success-function="cancelPackageSuccess">
                        @csrf
                        <input type="hidden" id="cancel-package-id" name="package_id" value="">

                        <div class="modal-body">
                            <p class="mb-3">{{ __('Are you sure you want to cancel this package subscription?') }}</p>

                            <div id="cancel-package-details" class="mt-3 p-3 bg-light rounded">
                                <p class="mb-2"><strong>{{ __('Package Name') }}:</strong> <span id="cancel-package-name" class="text-primary"></span></p>
                                <p class="mb-2"><strong>{{ __('Type') }}:</strong> <span id="cancel-package-type" class="badge bg-info"></span></p>
                                <p class="mb-2"><strong>{{ __('Start Date') }}:</strong> <span id="cancel-start-date"></span></p>
                                <p class="mb-0"><strong>{{ __('End Date') }}:</strong> <span id="cancel-end-date"></span></p>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                            <button type="submit" class="btn btn-danger" id="cancel-submit-btn">{{ __('Cancel Subscription') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </section>
@endsection
@section('js')
    <script>
        function assignApprovalSuccess() {
            $('#assignPackageModal').modal('hide');
            refreshTable();
        }
        
        function resetModal() {
            const modal = $('#assignPackageModal');
            const form = modal.find('form');
            form[0].reset();
            $('#package_details').hide();
            $('.payment').hide();
            $('.cheque').hide();
        }
        
        function resetManagePackagesModal() {
            $('#manage_user_id').val('');
            $('#active-packages-list').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">{{ __('Loading...') }}</span></div></div>');
        }
        
        function refreshTable() {
            if (typeof $('#table_list').bootstrapTable === 'function') {
                $('#table_list').bootstrapTable('refresh');
            } else {
                location.reload();
            }
        }
        
        function loadActivePackages(userId) {
            $.ajax({
                url: "{{ route('customer.active.packages') }}",
                type: 'GET',
                data: { user_id: userId },
                success: function(response) {
                    if (response.error === false && response.data) {
                        let html = '';
                        if (response.data.length === 0) {
                            html = '<p class="text-muted text-center">{{ __("No active packages found.") }}</p>';
                        } else {
                            html = '<div class="table-responsive"><table class="table table-bordered">';
                            html += '<thead><tr><th>{{ __("Package Name") }}</th><th>{{ __("Type") }}</th><th>{{ __("Start Date") }}</th><th>{{ __("End Date") }}</th><th>{{ __("Used/Total Limit") }}</th><th>{{ __("Remaining Days") }}</th><th>{{ __("Action") }}</th></tr></thead><tbody>';
                            
                            response.data.forEach(function(pkg) {
                                html += '<tr>';
                                html += '<td>' + (pkg.package_name || '-') + '</td>';
                                html += '<td><span class="badge bg-info">' + (pkg.package_type === 'item_listing' ? '{{ __("Item Listing") }}' : '{{ __("Advertisement") }}') + '</span></td>';
                                html += '<td>' + pkg.start_date + '</td>';
                                html += '<td>' + pkg.end_date + '</td>';
                                html += '<td>' + pkg.used_limit + ' / ' + (pkg.total_limit === 'Unlimited' || pkg.total_limit === null ? '{{ __("Unlimited") }}' : pkg.total_limit) + '</td>';
                                html += '<td>' + (pkg.remaining_days === 'unlimited' || pkg.remaining_days === 'Unlimited' ? '{{ __("Unlimited") }}' : pkg.remaining_days + ' {{ __("days") }}') + '</td>';
                                html += '<td><button class="btn btn-sm btn-danger cancel-package-btn" data-package-id="' + pkg.id + '">{{ __("Cancel") }}</button></td>';
                                html += '</tr>';
                            });
                            
                            html += '</tbody></table></div>';
                        }
                        $('#active-packages-list').html(html);
                    } else {
                        $('#active-packages-list').html('<p class="text-danger text-center">{{ __("Error loading packages.") }}</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading packages:', error);
                    $('#active-packages-list').html('<p class="text-danger text-center">{{ __("Error loading packages.") }}</p>');
                }
            });
        }
        
        // Handle manage packages button click
        $(document).on('click', '.manage_packages', function() {
            const userId = $(this).data('user-id');
            $('#manage_user_id').val(userId);
            $('#manage_user_id_form').val(userId);
            loadActivePackages(userId);
        });
        
        // Handle cancel package button click - Show cancel modal
        $(document).on('click', '.cancel-package-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const row = $(this).closest('tr');
            const packageId = $(this).data('package-id');
            
            if (!packageId) {
                alert('{{ __("Package ID is missing") }}');
                return false;
            }

            // Populate cancel modal with package details
            $('#cancel-package-id').val(packageId);
            
            const packageName = row.find('td:eq(0)').text().trim();
            const packageType = row.find('td:eq(1)').text().trim();
            const startDate = row.find('td:eq(2)').text().trim();
            const endDate = row.find('td:eq(3)').text().trim();
            
            $('#cancel-package-name').text(packageName || '-');
            $('#cancel-package-type').text(packageType || '-');
            $('#cancel-start-date').text(startDate || '-');
            $('#cancel-end-date').text(endDate || '-');

            // Show cancel modal
            $('#cancelSubscriptionModal').modal('show');
            return false;
        });

        // Success callback for cancel subscription form
        function cancelPackageSuccess(response) {
            // Close cancel subscription modal
            $('#cancelSubscriptionModal').modal('hide');
            
            // Reset cancel modal fields
            resetCancelModal();

            // Refresh packages inside manage modal if it's open
            const userId = $('#manage_user_id').val();
            if (userId) {
                setTimeout(function() {
                    loadActivePackages(userId);
                }, 500);
            }

            // Refresh main table
            setTimeout(function() {
                refreshTable();
            }, 500);
        }

        // Function to refresh manage packages list
        function refreshManagePackages() {
            const userId = $('#manage_user_id').val();
            if (userId) {
                loadActivePackages(userId);
            } else {
                alert('{{ __("Please select a user first") }}');
            }
        }

        // Function to reset cancel modal
        function resetCancelModal() {
            $('#cancel-package-id').val('');
            $('#cancel-package-name').text('');
            $('#cancel-package-type').text('');
            $('#cancel-start-date').text('');
            $('#cancel-end-date').text('');
            $('#cancel-submit-btn').prop('disabled', false);
        }

        // Reset cancel modal when closed
        $('#cancelSubscriptionModal').on('hidden.bs.modal', function () {
            resetCancelModal();
        });
        
        // Prevent form submission if package ID is missing
        $('#cancel-subscription-form').on('submit', function(e) {
            const packageId = $('#cancel-package-id').val();
            if (!packageId) {
                e.preventDefault();
                alert('{{ __("Package ID is missing. Please try again.") }}');
                return false;
            }
        });

    </script>
@endsection
