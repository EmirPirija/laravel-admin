@extends('layouts.main')

@section('title')
    {{ __('Seller Review Reports') }}
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
                        {{-- <div id="filters">
                            <label for="filter">{{__("Status")}}</label>
                            <select class="form-control bootstrap-table-filter-control-report-status" id="filter">
                                <option value="">{{__("All")}}</option>
                                <option value="reported">{{__("Reported")}}</option>
                                <option value="approved">{{__("Approved")}}</option>
                                <option value="rejected">{{__("Rejected")}}</option>
                            </select>
                        </div> --}}
                        <table class="table-borderless table-striped" aria-describedby="mydesc" id="table_list"
                               data-toggle="table" data-url="{{ route('seller-review.report',1) }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true"
                               data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                               data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                               data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false"
                               data-escape="true"
                               data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-pagination-successively-size="3" data-table="seller_ratings" data-status-column="deleted_at"
                               data-show-export="true" data-export-options='{"fileName": "seller-review-report-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                               data-mobile-responsive="true" data-filter-control="true" data-filter-control-container="#filters" data-toolbar="#filters">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                <th scope="col" data-field="seller_name" data-sortable="false">{{ __('Seller Name') }}</th>
                                <th scope="col" data-field="buyer_name" data-align="center" data-sortable="false">{{ __('Buyer Name') }}</th>
                                <th scope="col" data-field="item_name"  data-sortable="false">{{ __('Advertisement') }}</th>
                                <th scope="col" data-field="ratings" data-visible="true" data-Formatter="ratingFormatter">{{ __('Ratings') }}</th>
                                <th scope="col" data-field="review" data-sortable="false" data-formatter="descriptionFormatter">{{ __('Review') }}</th>
                                <th scope="col" data-field="report_status"  data-sortable="true" data-filter-control="select" data-filter-data="" data-formatter="reportStatusFormatter">{{ __('Report Status') }}</th>
                                <th scope="col" data-field="report_reason"  data-sortable="false">{{ __('Report Reason') }}</th>
                                <th scope="col" data-field="report_rejected_reason"  data-sortable="false">{{ __('Report Rejection Reason') }}</th>
                                @canany(['item-update','item-delete'])
                                <th scope="col" data-field="operate" data-align="center" data-sortable="false" data-events="reviewReportEvents" data-escape="false">{{ __('Action') }}</th>
                                @endcanany
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
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
                   <form class="edit-form" action="" method="POST" data-success-function="updateApprovalSuccess">
                       @csrf
                       <div class="row">
                           <div class="col-md-12">
                               <select name="report_status" class="form-select" id="report_status" aria-label="status">
                                   <option value="approved">{{__("Approve")}}</option>
                                   <option value="rejected">{{__("Reject")}}</option>
                               </select>
                           </div>
                       </div>
                       <div id="report_rejected_reason_container" class="col-md-12" style="display: none;">
                           <label for="rejected_reason" class="mandatory form-label">{{ __('Reason') }}</label>
                           <textarea name="report_rejected_reason" id="report_rejected_reason" class="form-control" placeholder={{ __('Reason') }}></textarea>
                       </div>
                       <input type="submit" value="{{__("Save")}}" class="btn btn-primary mt-3">
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
    </script>
@endsection
