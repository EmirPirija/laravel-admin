@extends('layouts.main')

@section('title')
    {{ __("Report Reasons") }}
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
    <div class="row">
        <section class="section">
            <div class="row">
                @can('report-reason-create')
                    <div class="col-md-4">
                        <div class="card">
                            <form action="{{ route('report-reasons.store') }}" class="needs-validation create-form" method="post" data-parsley-validate enctype="multipart/form-data">
                                <div class="card-body">
                                    <input type="hidden" name="type" value="0">
                                   <ul class="nav nav-tabs" role="tablist">
                                            @foreach($languages as $index => $language)
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link @if($index === 0) active @endif" data-bs-toggle="tab"
                                                            data-bs-target="#lang-{{ $language->id }}" type="button" role="tab">
                                                        {{ $language->name }}
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>

                                        <div class="tab-content mt-3">
                                            @foreach($languages as $index => $language)
                                                <div class="tab-pane fade @if($index === 0) show active @endif"
                                                    id="lang-{{ $language->id }}" role="tabpanel">
                                                    <div class="form-group">
                                                        <label>{{ __('Reason') }} ({{ $language->name }})</label>
                                                        <textarea name="reason[{{ $language->id }}]" class="form-control"
                                                                rows="3" {{ $language->code === 'en' ? 'required' : '' }}></textarea>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    <div class="col-md-12 d-flex justify-content-end">
                                        <button class="btn btn-primary" type="submit" name="submit">{{ __('Submit') }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endcan
                <div class="{{ \Illuminate\Support\Facades\Auth::user()->can('report-reason-create')? "col-md-8" : "col-md-12" }}">
                    <div class="card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12">
                                    <table class="table-light table-striped" aria-describedby="mydesc"
                                           id="table_list" data-toggle="table" data-url="{{ route('report-reasons.show',1) }}"
                                           data-click-to-select="true" data-responsive="true" data-side-pagination="server"
                                           data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                                           data-search="true" data-toolbar="#toolbar" data-show-columns="true"
                                           data-show-refresh="true" data-fixed-columns="true" data-fixed-number="1"
                                           data-fixed-right-number="1" data-trim-on-search="false" data-sort-name="id"
                                           data-sort-order="desc" data-pagination-successively-size="3"
                                           data-escape="true"
                                           data-query-params="reportReasonQueryParams"
                                           data-show-export="true" data-export-options='{"fileName": "advertisement-package-list","ignoreColumn": ["operate"]}' data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                                           data-mobile-responsive="true">
                                        <thead>
                                        <tr>
                                            <th scope="col" data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                            <th scope="col" data-field="reason" data-sortable="true">{{ __('Reason') }}</th>
                                            @canany(['report-reason-update','report-reason-delete'])
                                                <th scope="col" data-field="operate" data-escape="false" data-events="reportReasonEvents">{{ __('Action') }}</th>
                                            @endcanany
                                        </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="editModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel1"
                 aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel1">{{ __('Edit Reason') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                       <form action="" class="edit-form form-horizontal" enctype="multipart/form-data" method="POST" data-parsley-validate>
                                <div class="modal-body">
                                    <ul class="nav nav-tabs" role="tablist">
                                        @foreach($languages as $index => $language)
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link @if($index === 0) active @endif" data-bs-toggle="tab"
                                                        data-bs-target="#edit-lang-{{ $language->id }}" type="button" role="tab">
                                                    {{ $language->name }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="tab-content mt-3">
                                        @foreach($languages as $index => $language)
                                            <div class="tab-pane fade @if($index === 0) show active @endif" id="edit-lang-{{ $language->id }}" role="tabpanel">
                                                <div class="form-group">
                                                    <label>{{ __('Reason') }} ({{ $language->name }})</label>
                                                    <textarea class="form-control"
                                                            name="reason[{{ $language->id }}]"
                                                            id="edit_reason_{{ $language->id }}"
                                                            rows="3"
                                                            {{ $language->code === 'en' ? 'required' : '' }}></textarea>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary waves-effect" data-bs-dismiss="modal">{{ __('Close') }}</button>
                                <button type="submit" class="btn btn-primary waves-effect waves-light" id="btn_submit">{{ __('Save') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- /.modal-content -->
            </div>
        </section>
    </div>
@endsection
