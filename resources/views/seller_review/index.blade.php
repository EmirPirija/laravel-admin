@extends('layouts.main')

@section('title')
    {{ __('Seller Reviews') }}
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
                               data-toggle="table" data-url="{{ route('seller-review.show',1) }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true"
                               data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                               data-show-columns="true" data-show-refresh="true" data-fixed-columns="true"
                               data-fixed-number="1" data-fixed-right-number="1" data-trim-on-search="false"
                               data-escape="true"
                               data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-pagination-successively-size="3" data-table="seller_ratings" data-status-column="deleted_at"
                               data-show-export="true" data-export-options='{"fileName": "seller-review-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                               data-mobile-responsive="true" data-filter-control="true">
                            <thead class="thead-dark">
                            <tr>
                                <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                <th scope="col" data-field="seller_name" data-sortable="false">{{ __('Seller Name') }}</th>
                                <th scope="col" data-field="buyer_name" data-align="center" data-sortable="false">{{ __('Buyer Name') }}</th>
                                <th scope="col" data-field="item_name"  data-sortable="false">{{ __('Advertisement') }}</th>
                                <th scope="col" data-field="ratings" data-visible="true" data-Formatter="ratingFormatter">{{ __('Ratings') }}</th>
                                <th scope="col" data-field="review" data-sortable="true" data-formatter="descriptionFormatter">{{ __('Review') }}</th>
                            </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

