@extends('layouts.main')

@section('title')
    {{ __('Web Settings') }}
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

@section('css')
    <style>
        .campaign-badge-settings-card {
            border: 1px solid rgba(40, 63, 164, 0.14);
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(248, 250, 255, 0.98) 0%, rgba(255, 255, 255, 0.98) 100%);
            box-shadow: 0 16px 40px -26px rgba(34, 48, 84, 0.46);
        }

        .campaign-badge-settings-card .campaign-header-title {
            font-weight: 700;
            color: #1F2A44;
        }

        .campaign-badge-json-input {
            min-height: 280px;
            font-size: 13px;
            line-height: 1.45;
            border-radius: 12px;
            border: 1px solid #D8DEF5;
            background: #FAFCFF;
        }

        .campaign-preview-panel {
            border: 1px solid #E3E8FF;
            border-radius: 12px;
            background: #FFFFFF;
            padding: 14px;
            min-height: 280px;
        }

        .campaign-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .campaign-preview-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 10px;
            border: 1px solid rgba(20, 30, 60, 0.08);
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .campaign-preview-hint {
            color: #6B7280;
            font-size: 12px;
        }
    </style>
@endsection

@section('content')
    <section class="section">
        <form class="create-form-without-reset" action="{{route('settings.store') }}" method="post" enctype="multipart/form-data" data-success-function="successFunction" data-parsley-validate>
            @csrf
            <div class="row d-flex mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Web Settings') }}</h6>
                    </div>
                    <div class="row">
                        @php
                            $listingCampaignBadgesRaw = $settings['listing_campaign_badges'] ?? '[]';
                            $listingCampaignBadgesPretty = $listingCampaignBadgesRaw;
                            if (is_string($listingCampaignBadgesRaw) && trim($listingCampaignBadgesRaw) !== '') {
                                $decodedListingCampaignBadges = json_decode($listingCampaignBadgesRaw, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $listingCampaignBadgesPretty = json_encode(
                                        $decodedListingCampaignBadges,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    );
                                }
                            }
                        @endphp
                        <div class="form-group col-md-6 col-sm-12">
                            <label for="web_theme_color" class="form-label ">{{ __('Theme Color') }}</label>
                            <input id="web_theme_color" name="web_theme_color" type="color" class="form-control form-control-color" placeholder="{{ __('Theme Color') }}" value="{{ $settings['web_theme_color'] ?? '' }}">
                        </div>
                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Header Logo') }}</label>
                            <input class="filepond" type="file" name="header_logo" id="header_logo">
                            <img src="{{ $settings['header_logo'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/Header Logo.svg')}}" class="w-25" alt="image">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Footer Logo') }}</label>
                            <input class="filepond" type="file" name="footer_logo" id="footer_logo">
                            <img src="{{ $settings['footer_logo'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/Footer Logo.svg')}}" class="w-25" alt="image">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label ">{{ __('Placeholder image') }} <small>{{__('(This image will be displayed if no image is available.)')}}</small></label>
                            <input class="filepond" type="file" name="placeholder_image" id="placeholder_image">
                            <img src="{{ $settings['placeholder_image'] ?? '' }}" data-custom-image="{{asset('assets/images/logo/favicon.png')}}" alt="image" style="height: 31%;width: 21%;">
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="footer_description" class="form-label ">{{ __('Footer Description') }}</label>
                            <textarea id="footer_description" name="footer_description" class="form-control" rows="5" placeholder="{{ __('Footer Description') }}">{{ $settings['footer_description'] ?? '' }}</textarea>
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="google_map_iframe_link" class="form-label ">{{ __('Google Map Iframe Link') }}</label>
                            <textarea id="google_map_iframe_link" name="google_map_iframe_link" type="text" class="form-control" rows="5" placeholder="{{ __('Google Map Iframe Link') }}">{{ $settings['google_map_iframe_link'] ?? '' }}</textarea>
                        </div>

                         @if($languages_translate->isNotEmpty())
                        <div class="col-md-12 mt-3">
                            <hr>
                            <h5>{{ __("Translations") . " (" . __("Optional") . ")" }}</h5>
                        </div>

                        @foreach($languages_translate as $language)
                            <div class="col-md-6 mb-4 p-3 rounded">
                                <h6 class="mb-3 text-primary">
                                    {{ __("Translation for") }}: <strong>{{ $language->name }} ({{ $language->code }})</strong>
                                </h6>

                                <input type="hidden" name="translations[{{ $language->id }}][name]" value="footer_description">

                                <div class="form-group">
                                    <label for="translation_{{ $language->id }}" class="form-label">
                                        {{ __("Translated Footer Description") }}
                                    </label>
                                    <textarea class="form-control"
                                              name="translations[{{ $language->id }}][value]"
                                              rows="4"
                                              placeholder="{{ __('Contact Us in') . ' ' . $language->name }}">
                                        {{ old("translations.{$language->id}.value", $translations['footer_description'][$language->id] ?? '') }}
                                    </textarea>
                                </div>
                            </div>
                        @endforeach
                    @endif

                        <div class="form-group col-md-6 col-sm-12">
                            <label for="google_map_iframe_link" class="form-label ">{{ __('Default Latitude & Longitude') }} <small>{{__('(For Default Location Selection)')}}</small></label>
                            <div class="form-group">
                                <label for="default_latitude" class="form-label ">{{ __('Latitude') }}</label>
                                <input id="default_latitude" name="default_latitude" type="text" class="form-control" placeholder="{{ __('Latitude') }}" value="{{ $settings['default_latitude'] ?? '' }}">
                                <label for="default_longitude" class="form-label ">{{ __('Longitude') }}</label>
                                <input id="default_longitude" name="default_longitude" type="text" class="form-control" placeholder="{{ __('Longitude') }}" value="{{ $settings['default_longitude'] ?? '' }}">
                            </div>
                        </div>

                        <div class="form-group col-md-6 col-sm-12">
                            <label class="form-label">{{ __('Show Landing Page') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="show_landing_page" value="0">
                                <input class="form-check-input" type="checkbox" id="show_landing_page" name="show_landing_page" value="1" {{ isset($settings['show_landing_page']) && $settings['show_landing_page'] == 1 ? 'checked' : '' }}>
                                <label class="form-check-label" for="show_landing_page">
                                    {{ __('On / Off') }}
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="campaign-badge-settings-card card border-0 mb-1">
                                <div class="card-body">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-3">
                                        <div>
                                            <h5 class="campaign-header-title mb-1">{{ __('Seasonal listing labels') }}</h5>
                                            <p class="text-muted mb-0">
                                                {{ __('Control event/season labels sellers can attach to listings from create/edit form.') }}
                                            </p>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="hidden" name="listing_campaign_badges_enabled" value="0">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="listing_campaign_badges_enabled"
                                                name="listing_campaign_badges_enabled"
                                                value="1"
                                                {{ isset($settings['listing_campaign_badges_enabled']) && (int)$settings['listing_campaign_badges_enabled'] === 1 ? 'checked' : '' }}
                                            >
                                            <label class="form-check-label fw-semibold ms-1" for="listing_campaign_badges_enabled">
                                                {{ __('Enable for sellers') }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12 col-xl-8">
                                            <label for="listing_campaign_badges" class="form-label fw-semibold">
                                                {{ __('Label options (JSON)') }}
                                            </label>
                                            <textarea
                                                id="listing_campaign_badges"
                                                name="listing_campaign_badges"
                                                rows="9"
                                                class="form-control font-monospace campaign-badge-json-input"
                                                placeholder='[{"key":"valentinovo","label":"Valentinovo"},{"key":"8-mart","label":"8. mart"}]'
                                            >{{ $listingCampaignBadgesPretty }}</textarea>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <button type="button" id="listing_campaign_badges_fill_example" class="btn btn-sm btn-light">
                                                    {{ __('Insert example') }}
                                                </button>
                                                <button type="button" id="listing_campaign_badges_validate" class="btn btn-sm btn-outline-primary">
                                                    {{ __('Validate format') }}
                                                </button>
                                            </div>
                                            <small class="text-muted d-block mt-2">
                                                {{ __('Format: array of objects with key + label, optional bg_color/text_color.') }}
                                            </small>
                                            <small class="text-muted d-block">
                                                <code>[{"key":"bajram","label":"Bajram","bg_color":"#065F46","text_color":"#FFFFFF"}]</code>
                                            </small>
                                        </div>
                                        <div class="col-12 col-xl-4">
                                            <div class="campaign-preview-panel">
                                                <h6 class="mb-2">{{ __('Live preview') }}</h6>
                                                <div id="listing_campaign_badges_preview" class="campaign-preview-grid"></div>
                                                <div id="listing_campaign_badges_preview_hint" class="campaign-preview-hint mt-2">
                                                    {{ __('No labels configured yet.') }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                    <div class="divider pt-3">
                        <h6 class="divider-text">{{ __('Social Media Links') }}</h6>
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="instagram_link" class="form-label ">{{ __('Instagram Link') }}</label>
                        <input id="instagram_link" name="instagram_link" type="url" class="form-control" placeholder="{{ __('Instagram Link') }}" value="{{ $settings['instagram_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="x_link" class="form-label ">{{ __('X Link') }}</label>
                        <input id="x_link" name="x_link" type="url" class="form-control" placeholder="{{ __('X Link') }}" value="{{ $settings['x_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="facebook_link" class="form-label ">{{ __('Facebook Link') }}</label>
                        <input id="facebook_link" name="facebook_link" type="url" class="form-control" placeholder="{{ __('Facebook Link') }}" value="{{ $settings['facebook_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="linkedin_link" class="form-label ">{{ __('Linkedin Link') }}</label>
                        <input id="linkedin_link" name="linkedin_link" type="url" class="form-control" placeholder="{{ __('Linkedin Link') }}" value="{{ $settings['linkedin_link'] ?? '' }}">
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="pinterest_link" class="form-label ">{{ __('Pinterest Link') }}</label>
                        <input id="pinterest_link" name="pinterest_link" type="url" class="form-control" placeholder="{{ __('Pinterest Link') }}" value="{{ $settings['pinterest_link'] ?? '' }}">
                    </div>
                </div>
                </div>
            </div>
            <div class="col-12 d-flex justify-content-end">
                <button type="submit" value="btnAdd" class="btn btn-primary me-1 mb-3">{{ __('Save') }}</button>
            </div>
        </form>
    </section>
@endsection
@section('js')
    <script>
        function successFunction() {
            window.location.reload();
        }

        (function () {
            const defaultExample = JSON.stringify([
                { key: 'valentinovo', label: 'Valentinovo', bg_color: '#DB2777', text_color: '#FFFFFF' },
                { key: '8-mart', label: '8. mart', bg_color: '#0EA5E9', text_color: '#FFFFFF' },
                { key: 'ramazan', label: 'Ramazan', bg_color: '#065F46', text_color: '#FFFFFF' }
            ], null, 2);

            function normalizeHexColor(value, fallback) {
                if (typeof value !== 'string') {
                    return fallback;
                }
                const trimmed = value.trim();
                if (!/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(trimmed)) {
                    return fallback;
                }
                return trimmed;
            }

            function normalizeOptions(rawInput) {
                if (rawInput === null || rawInput === undefined) {
                    return [];
                }

                let source = rawInput;
                if (typeof rawInput === 'string') {
                    const trimmed = rawInput.trim();
                    if (!trimmed) {
                        return [];
                    }

                    try {
                        source = JSON.parse(trimmed);
                    } catch (jsonError) {
                        source = trimmed.split(/[\r\n,;]+/g).map((entry) => entry.trim()).filter(Boolean);
                    }
                }

                if (!Array.isArray(source)) {
                    if (source && typeof source === 'object') {
                        source = Object.values(source);
                    } else {
                        return [];
                    }
                }

                const seen = new Set();
                const options = [];
                source.forEach((entry) => {
                    let label = '';
                    let key = '';
                    let bgColor = '#E8F1FF';
                    let textColor = '#1F2A44';

                    if (typeof entry === 'string' || typeof entry === 'number') {
                        label = String(entry).trim();
                        key = label.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
                    } else if (entry && typeof entry === 'object') {
                        label = String(entry.label || entry.name || '').trim();
                        key = String(entry.key || label).trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
                        bgColor = normalizeHexColor(entry.bg_color || entry.background_color, '#E8F1FF');
                        textColor = normalizeHexColor(entry.text_color || entry.foreground_color, '#1F2A44');
                    }

                    if (!label || !key || seen.has(key)) {
                        return;
                    }

                    seen.add(key);
                    options.push({
                        key: key,
                        label: label,
                        bg_color: bgColor,
                        text_color: textColor
                    });
                });

                return options;
            }

            function notify(type, message) {
                if (window.toastr && typeof window.toastr[type] === 'function') {
                    window.toastr[type](message);
                    return;
                }
                if (type === 'error') {
                    console.error(message);
                } else {
                    console.log(message);
                }
            }

            function renderPreview(options, previewContainer, previewHint) {
                if (!previewContainer || !previewHint) {
                    return;
                }
                previewContainer.innerHTML = '';
                if (!options.length) {
                    previewHint.style.display = 'block';
                    return;
                }

                previewHint.style.display = 'none';
                options.forEach((option) => {
                    const chip = document.createElement('span');
                    chip.className = 'campaign-preview-chip';
                    chip.textContent = option.label;
                    chip.style.backgroundColor = option.bg_color || '#E8F1FF';
                    chip.style.color = option.text_color || '#1F2A44';
                    previewContainer.appendChild(chip);
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                const textarea = document.getElementById('listing_campaign_badges');
                const previewContainer = document.getElementById('listing_campaign_badges_preview');
                const previewHint = document.getElementById('listing_campaign_badges_preview_hint');
                const fillExampleButton = document.getElementById('listing_campaign_badges_fill_example');
                const validateButton = document.getElementById('listing_campaign_badges_validate');

                if (!textarea) {
                    return;
                }

                const refreshPreview = function () {
                    const options = normalizeOptions(textarea.value);
                    renderPreview(options, previewContainer, previewHint);
                    return options;
                };

                refreshPreview();
                textarea.addEventListener('input', refreshPreview);

                if (fillExampleButton) {
                    fillExampleButton.addEventListener('click', function () {
                        if (!textarea.value.trim()) {
                            textarea.value = defaultExample;
                            refreshPreview();
                            notify('success', 'Example inserted.');
                            return;
                        }
                        notify('error', 'Clear current value first, then insert example.');
                    });
                }

                if (validateButton) {
                    validateButton.addEventListener('click', function () {
                        const parsed = refreshPreview();
                        if (!parsed.length && textarea.value.trim() !== '') {
                            notify('error', 'Could not parse labels. Please use valid JSON or one label per line.');
                            return;
                        }
                        notify('success', 'Label format looks valid.');
                    });
                }
            });
        })();
    </script>
@endsection
