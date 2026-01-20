@extends('layouts.main')
@section('title')
    {{__("Verification Fields")}}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row d-flex align-items-center">
            <div class="col-12 col-md-6">
                <h4 class="mb-0">@yield('title')</h4>
            </div>
            <div class="col-12 col-md-6 text-end">
                @can('custom-field-create')
                    <a href="{{ route('seller-verification.create') }}" class="btn btn-primary mb-0">+ {{__("Create Verification Field")}} </a>
                @endcan
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
                        <table class="stable-borderless table-striped" aria-describedby="mydesc" id="table_list"
                               data-toggle="table" data-url="{{ route('verification-field.show') }}" data-click-to-select="true"
                               data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                               data-search="true" data-search-align="right" data-toolbar="#filters" data-show-columns="true"
                               data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1" data-fixed-right-number="1"
                               data-trim-on-search="false" data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-pagination-successively-size="3" data-escape="true"
                               data-show-export="true" data-export-options='{"fileName": "verification-fields-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                               data-mobile-responsive="true" data-filter-control="true" data-filter-control-container="#filters">
                            <thead class="thead-dark">
                                <tr>
                                    <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="name" data-align="center" data-sortable="true">{{ __('Name') }}</th>
                                    <th scope="col" data-field="min_length" data-align="center" data-sortable="true">{{ __('Min Length') }}</th>
                                    <th scope="col" data-field="max_length" data-align="center" data-sortable="true">{{ __('Max Length') }}</th>
                                    <th scope="col" data-field="values" data-align="center" data-sortable="true" data-formatter="rejectedReasonFormatter">{{ __('Values') }}</th>
                                    {{-- <th scope="col" data-field="status" data-align="center" data-sortable="true" data-filter-control="select" data-formatter="sellerverificationStatusFormatter">{{ __('Status') }}</th> --}}
                                    @canany(['seller-verification-field-update','seller-verification-field-delete'])
                                        <th scope="col" data-field="operate" data-align="center" data-sortable="false" data-escape="false" data-events="verificationfeildEvents">{{ __('Action') }}</th>
                                    @endcanany
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

