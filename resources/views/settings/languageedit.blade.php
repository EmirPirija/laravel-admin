@extends('layouts.main')

@section('title')
    {{ __('Edit Language') }}
@endsection

@section('content')
    <section class="section">
        <div class="row">
            <form action="{{ route('updatelanguage', ['id' => $language->id, 'type' => $type]) }}" method="POST" enctype="multipart/form-data" class="editlanguage-form">
                @csrf
                @method('PUT')
                <div class="card">
                    <div class="card-header">{{ __("Language JSON - " .($type)) }}
                        <!-- <button type="button" class="btn btn-secondary float-end" onclick="location.href='{{ route('auto-translate', ['id' => $language->id, 'type' => $type, 'locale' => $language->code]) }}'">
                            Auto Translate
                        </button> -->

                    </div>
                    <div class="card-body mt-3">
                        <div class="row">
                            @foreach($enLabels as $key => $value)
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="value-{{ $loop->index }}" class="form-labe">{{$key}}</label>
                                            <input type="text" class="form-control" id="value-{{ $loop->index }}" name="values[]" value="{{ $value }}" required>
                                        </div>
                                    </div>
                            @endforeach
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-primary">{{__("Save Changes")}}</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
