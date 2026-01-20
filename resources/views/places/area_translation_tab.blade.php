@if($languages->isNotEmpty())
    <ul class="nav nav-tabs mb-3">
        @foreach($languages as $index => $language)
            <li class="nav-item">
                <a class="nav-link @if($index == 0) active @endif"
                   data-bs-toggle="tab"
                   href="#lang-{{ $language->id }}-{{ $city->id }}">
                    {{ $language->name }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mb-4">
        @foreach($languages as $index => $language)
            <div class="tab-pane fade @if($index == 0) show active @endif"
                 id="lang-{{ $language->id }}-{{ $city->id }}">
                <div class="row">
                    @foreach($areas as $area)
                        @php
                            $translation = $area->translations->firstWhere('language_id', $language->id);
                        @endphp
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ $area->name }}</label>
                            <input type="text"
                                   name="translations[{{ $language->id }}][{{ $area->id }}]"
                                   class="form-control"
                                   value="{{ $translation?->name }}"
                                   placeholder="{{ __('Enter name for') }} {{ $area->name }}">
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
