@extends('layouts.main')

@section('title')
    {{ __('Bank Transfers') }}
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
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">

                        {{-- <div class="row " id="toolbar"> --}}

                        <div class="row">
                            <div class="col-12">
                                <table class="table table-borderless table-striped" aria-describedby="mydesc"
                                       id="table_list" data-toggle="table" data-url="{{ route('package.bank-transfer.show') }}"
                                       data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                                       data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                                       data-search-align="right" data-toolbar="#toolbar" data-show-columns="true"
                                       data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1"
                                       data-fixed-right-number="1" data-trim-on-search="false" data-responsive="true"
                                       data-sort-name="id" data-sort-order="desc" data-pagination-successively-size="3"
                                       data-escape="true"
                                       data-query-params="queryParams" data-table="packages"
                                       data-show-export="true" data-export-options='{"fileName": "user-package-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                                       data-mobile-responsive="true">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                        <th scope="col" data-field="user.name" data-align="center" data-sortable="false">{{ __('User Name') }}</th>
                                        <th scope="col" data-field="amount" data-align="center" data-sortable="false">{{ __('Amount') }}</th>
                                        {{-- <th scope="col" data-field="payment_gateway" data-align="center">{{ __('Payment Gateway') }}</th> --}}
                                        <th scope="col" data-field="payment_status" data-align="center" data-sortable="true">{{ __('Payment Status') }}</th>
                                        <th scope="col" data-field="payment_receipt" data-align="center" data-sortable="false" data-formatter="imageFormatter">{{ __('Payment Reciept') }}</th>
                                        <th scope="col" data-field="created_at" data-align="center" data-sortable="true">{{ __('Created At') }}</th>
                                        <th scope="col" data-field="operate" data-escape="false" data-align="center" data-sortable="false">{{ __('Action') }}</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
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
                                    <select name="payment_status" class="form-select" id="verification_status" aria-label="status">
                                        <option value="succeed">{{ __("Approved") }}</option>
                                        <option value="rejected">{{ __("Rejected") }}</option>
                                    </select>
                                </div>
                            </div>
                            <input type="submit" value="{{ __("Save") }}" class="btn btn-primary mt-3">
                        </form>
                    </div>
                </div>
            </div>
            <!-- /.modal-content -->
        </div>
    </section>
@endsection
