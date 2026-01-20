@extends('layouts.main')

@section('title')
    {{ __('File Manager') }}
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
        <form class="create-form-without-reset" action="{{ route('settings.file-manager.store') }}" method="post" enctype="multipart/form-data">
            <div class="row d-flex mb-3">
                <div class="col-md-6 mt-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="divider pt-3">
                                <h6 class="divider-text">{{ __('File Manager Setting') }}</h6>
                            </div>
                            <div class="form-group row">
                                <div class="col-sm-12 mt-2">
                                    <label for="file_manager" class="col-sm-12 form-check-label  mt-2">{{ __('File Manager') }}</label>
                                    <select name="file_manager" id="file_manager" class="form-select form-control-sm">
                                        <option value="public">{{ __('Local Server') }}</option>
                                        <option value="s3">{{ __('AWS S3') }}</option>
                                    </select>
                                </div>

                                <div id="s3_div" style="display: none">
                                    <label for="stripe_secret_key" class="col-sm-12 form-check-label  mt-2">{{ __('AWS Access Key ID') }}</label>
                                    <div class="col-sm-12 mt-2">
                                        <input id="stripe_secret_key" name="S3_aws_access_key_id" type="text" class="form-control" placeholder="{{ __('AWS Access Key ID') }}" value="{{ $settings['S3_aws_access_key_id'] ?? '' }}" >
                                    </div>

                                    <label for="stripe_publishable_key" class="col-sm-12 form-check-label  mt-2">{{ __('AWS Secret Access Key') }}</label>
                                    <div class="col-sm-12 mt-2">
                                        <input id="AWS_SECRET_ACCESS_KEY" name="s3_aws_secret_access_key" type="text" class="form-control" placeholder="{{ __('AWS Secret Access Key') }}" value="{{ $settings['s3_aws_secret_access_key'] ?? '' }}" >
                                    </div>

                                    <label for="stripe_webhook_secret" class="col-sm-12 form-check-label  mt-2">{{ __('AWS Default Region') }}</label>
                                    <div class="col-sm-12 mt-2">
                                        <input id="AWS_DEFAULT_REGION" name="s3_aws_default_region" type="text" class="form-control" placeholder="{{ __('AWS Default Region') }}" value="{{ $settings['s3_aws_default_region'] ?? '' }}" >
                                    </div>

                                    <label for="stripe_webhook_url" class="col-sm-12 form-check-label  mt-2">{{ __('AWS Bucket') }}</label>
                                    <div class="col-sm-12 mt-2">
                                        <input id="AWS_BUCKET" name="s3_aws_bucket" type="text" class="form-control" placeholder="{{ __('AWS Bucket') }}" value="{{ $settings['s3_aws_default_region'] ?? '' }}" >
                                    </div>
                                    <label for="stripe_webhook_url" class="col-sm-12 form-check-label  mt-2">{{ __('AWS URL') }}</label>
                                    <div class="col-sm-12 mt-2">
                                        <input id="AWS_URL" name="s3_aws_url" type="text" class="form-control" placeholder="{{ __('AWS URL') }}" value="{{ $settings['s3_aws_url'] ?? '' }}" >
                                    </div>
                                </div>

                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
                            </div>
                        </div>
                    </div>

                </div>
                {{--Stripe Payment Gateway END--}}



            </div>

        </form>
    </section>
@endsection

@section('script')
    <script type="text/javascript">
        $('#file_manager').val("{{$settings['file_manager'] ?? ''}}").trigger("change");
    </script>
@endsection

