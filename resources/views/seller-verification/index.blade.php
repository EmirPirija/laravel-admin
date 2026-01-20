@extends('layouts.main')
@section('title')
    {{__("Seller Verification")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
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
                        <table class="stable-borderless table-striped" aria-describedby="mydesc" id="table_list" data-toggle="table" data-url="{{ route('verification_requests.show') }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-search-align="right" data-toolbar="#filters" data-show-columns="true" data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false" data-responsive="true" data-sort-name="id" data-sort-order="desc" data-pagination-successively-size="3" data-escape="true" data-show-export="true" data-export-options='{"fileName": "verification_requests-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']" data-mobile-responsive="true" data-filter-control="true" data-filter-control-container="#filters">
                            <thead class="thead-dark">
                                <tr>
                                    <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="user_name" data-align="center" data-sortable="true">{{ __('User') }}</th>
                                    <th scope="col" data-field="status" data-align="center" data-sortable="true" data-filter-control="select" data-formatter="sellerverificationStatusFormatter">{{ __('Status') }}</th>
                                    @canany(['seller-verification-request-update'])
                                        <th scope="col" data-field="operate" data-align="center" data-sortable="false" data-escape="false" data-events="verificationEvents">{{ __('Action') }}</th>
                                    @endcanany
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="editStatusModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Status') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="edit-form" action="" method="POST">
                            @csrf
                            <div class="row">
                                <div class="col-md-12">
                                    <select name="status" class="form-select" id="verification_status" aria-label="status">
                                        <option value="pending">{{ __("Pending") }}</option>
                                        <option value="approved">{{ __("Approved") }}</option>
                                        <option value="rejected">{{ __("Rejected") }}</option>
                                    </select>
                                </div>
                                <div class="form-group " id="rejectionReasonField" style="display: none;">
                                    <label for="rejection_reason">{{ __("Rejection Reason") }} </label><span class="text-danger">*</span>
                                    <textarea id="rejection_reason" name="rejection_reason" class="form-control"></textarea>
                                </div>
                            </div>
                            <input type="submit" value="{{ __("Save") }}" class="btn btn-primary mt-3">
                        </form>
                    </div>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>
        <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="myModalLabel1">{{ __('Verification Details') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="center" id="verification_fields"></div>
                    </div>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>

    </section>
@endsection
