@if($languages->isNotEmpty())
    <ul class="nav nav-tabs mb-3">
        @foreach($languages as $index => $language)
            <li class="nav-item">
                <a class="nav-link @if($index == 0) active @endif"
                   data-bs-toggle="tab"
                   href="#lang-{{ $language->id }}-{{ $state->id }}">
                    {{ $language->name }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mb-4">
        @foreach($languages as $index => $language)
            <div class="tab-pane fade @if($index == 0) show active @endif"
                 id="lang-{{ $language->id }}-{{ $state->id }}">
                <div class="row">
                    @foreach($cities as $city)
                        @php
                            $translation = $city->translations->firstWhere('language_id', $language->id);
                        @endphp
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ $city->name }}</label>
                            <input type="text"
                                   name="translations[{{ $language->id }}][{{ $city->id }}]"
                                   class="form-control"
                                   value="{{ $translation?->name }}"
                                   placeholder="{{ __('Enter name for') }} {{ $city->name }}">
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
