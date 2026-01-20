@extends('layouts.main')

@section('title')
    {{ __('Membership Packages (Pro & Shop)') }}
@endsection

@section('page-title')
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h4>@yield('title')</h4>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <section class="section">
        <div class="row">
            @can('membership-package-create')
                <div class="col-md-4">
                    <div class="card">
                        {!! Form::open(['route' => 'package.membership.store', 'data-parsley-validate', 'files' => true,'class'=>'create-form']) !!}
                        <div class="card-body">
                            <ul class="nav nav-tabs" id="langTabs" role="tablist">
                                @foreach($languages as $key => $lang)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link @if($key == 0) active @endif" id="tab-{{ $lang->id }}" data-bs-toggle="tab" data-bs-target="#lang-{{ $lang->id }}" type="button" role="tab">
                                            {{ $lang->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="tab-content mt-3">
                                @foreach($languages as $key => $lang)
                                    <div class="tab-pane fade @if($key == 0) show active @endif" id="lang-{{ $lang->id }}" role="tabpanel">
                                        <input type="hidden" name="languages[]" value="{{ $lang->id }}">

                                        <div class="form-group">
                                            <label>{{ __('Name') }} ({{ $lang->name }})</label>
                                            <input type="text" 
                                                name="name[{{ $lang->id }}]" 
                                                class="form-control" 
                                                placeholder="{{ __('e.g. LMX Pro, LMX Shop') }}"
                                                value=""
                                                @if($lang->id == 1) data-parsley-required="true" @endif>
                                        </div>

                                        <div class="form-group">
                                            <label>{{ __('Description') }} ({{ $lang->name }})</label>
                                            <textarea name="description[{{ $lang->id }}]" class="form-control" rows="3" @if($lang->id == 1) data-parsley-required="true" @endif></textarea>
                                        </div>

                                        @if($lang->id == 1)
                                            <!-- Membership Type -->
                                            <div class="col-md-12 form-group mandatory">
                                                {{ Form::label('membership_tier', __('Membership Tier'), ['class' => 'form-label col-12']) }}
                                                <select name="membership_tier" class="form-control" data-parsley-required="true">
                                                    <option value="">{{ __('Select Tier') }}</option>
                                                    <option value="pro">LMX Pro</option>
                                                    <option value="shop">LMX Shop</option>
                                                </select>
                                            </div>

                                            <div class="row mt-3">
                                                <div class="col-md-12 col-12 form-group">
                                                    {{ Form::label('ios_product_id', __('IOS Product ID'), ['class' => 'form-label col-12']) }}
                                                    {{ Form::text('ios_product_id', '', [
                                                        'class' => 'form-control',
                                                        'placeholder' => __("IOS Product ID"),
                                                        'id' => 'ios_product_id',
                                                    ]) }}
                                                </div>

                                                <div class="col-md-6 col-12 form-group mandatory">
                                                    {{ Form::label('price', __('Price') . ' (' . $currency_symbol . ')', ['class' => 'form-label col-12']) }}
                                                    {{ Form::number('price', 0, [
                                                        'class' => 'form-control',
                                                        'placeholder' => __('Package Price'),
                                                        'data-parsley-required' => 'true',
                                                        'id' => 'price',
                                                        'min' => '0',
                                                        'step'=>0.01,
                                                    ]) }}
                                                </div>

                                                <div class="col-md-6 col-12 form-group mandatory">
                                                    {{ Form::label('discount_in_percentage', __('Discount') . ' (%)', ['class' => 'form-label col-12']) }}
                                                    {{ Form::number('discount_in_percentage', 0, [
                                                        'class' => 'form-control',
                                                        'placeholder' => __('Discount'),
                                                        'data-parsley-required' => 'true',
                                                        'id' => 'discount_in_percentage',
                                                        'min' => '0',
                                                        'max'=>'100',
                                                        'step'=>0.01,
                                                    ]) }}
                                                </div>

                                                <div class="col-md-12 col-12 form-group mandatory">
                                                    {{ Form::label('final_price', __('Final Price') . ' (' . $currency_symbol . ')', ['class' => 'form-label col-12']) }}
                                                    {{ Form::number('final_price', 0, [
                                                        'class' => 'form-control',
                                                        'placeholder' => __('Final Price'),
                                                        'data-parsley-required' => 'true',
                                                        'id' => 'final_price',
                                                        'min' => '0',
                                                        'step'=>0.01
                                                    ]) }}
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <div class="col-md-12 form-group mandatory">
                                                    <label for="icon" class="mandatory form-label">{{ __('Icon/Image') }}</label>
                                                    <input type="file" name="icon" id="icon" class="form-control" data-parsley-required="true" accept=".jpg,.jpeg,.png">
                                                </div>
                                            </div>

                                            <!-- Duration (days) -->
                                            <div class="col-md-12 col-sm-12 form-group">
                                                <div class="row">
                                                    {{ Form::label('duration', __('Duration (Days)'), ['class' => 'form-label col-12']) }}
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <div class="input-group-text myDivClass" style="height: 42px;">
                                                                <span class="mySpanClass">{{__("Days")}}</span>
                                                            </div>
                                                        </div>
                                                        {{ Form::number('duration', 30, [
                                                            'class' => 'form-control',
                                                            'type' => 'number',
                                                            'min' => '1',
                                                            'placeholder' => __('30'),
                                                            'id' => 'durationLimit',
                                                            'style' => 'height: 42px;',
                                                            'data-parsley-required' => 'true',
                                                        ]) }}
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Features (JSON) -->
                                            <div class="col-md-12 form-group">
                                                <label>{{ __('Features (one per line)') }}</label>
                                                <textarea name="features" class="form-control" rows="5" placeholder="Unlimited Listings&#10;Priority Support&#10;Advanced Analytics"></textarea>
                                                <small class="text-muted">{{ __('Enter one feature per line') }}</small>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="col-md-12 col-12 text-end form-group pt-4">
                                {{ Form::submit(__('Add Membership Package'), ['class' => 'center btn btn-primary', 'style' => 'width:250px']) }}
                            </div>
                        </div>
                        {!! Form::close() !!}
                    </div>
                </div>
            @endcan

            <div class="{{ Auth::user()->can('membership-package-create') ? 'col-md-8' : 'col-md-12' }}">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                <table class="table table-borderless table-striped" aria-describedby="mydesc"
                                       id="table_list" data-toggle="table" data-url="{{ route('package.membership.show') }}"
                                       data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                                       data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true"
                                       data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                                       data-sort-name="id" data-sort-order="desc"
                                       data-query-params="queryParams" data-table="packages"
                                       data-show-export="true" data-mobile-responsive="true">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                        <th scope="col" data-field="icon" data-formatter="imageFormatter" data-align="center">{{ __('Icon') }}</th>
                                        <th scope="col" data-field="name" data-align="center" data-sortable="true">{{ __('Name') }}</th>
                                        <th scope="col" data-field="membership_tier" data-align="center" data-sortable="true">{{ __('Tier') }}</th>
                                        <th scope="col" data-field="price" data-align="center" data-sortable="true">{{ __('Price') }}</th>
                                        <th scope="col" data-field="discount_in_percentage" data-align="center" data-sortable="true">{{ __('Discount (%)') }}</th>
                                        <th scope="col" data-field="final_price" data-align="center" data-sortable="true">{{ __('Final Price') }}</th>
                                        <th scope="col" data-field="duration" data-align="center" data-sortable="true">{{ __('Days') }}</th>
                                        <th scope="col" data-field="description" data-align="center" data-visible="false">{{ __('Description') }}</th>
                                        @can('membership-package-update')
                                            <th scope="col" data-field="status" data-align="center" data-formatter="statusSwitchFormatter">{{ __('Status') }}</th>
                                            <th scope="col" data-field="operate" data-align="center" data-events="membershipPackageEvents">{{ __('Action') }}</th>
                                        @endcan
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
