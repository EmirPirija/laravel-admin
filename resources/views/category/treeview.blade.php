@foreach ($categories as $category)
    <div class="category">
        <div class="category-header">
            <label>
                <input type="checkbox" 
                       name="selected_categories[]" 
                       value="{{ $category->id }}" 
                       {{ in_array($category->id, $selected_categories) ? "checked" : "" }}>
                {{ $category->name }}
            </label>
            @if (!empty($category->subcategories))
                @php
                    // Get current language from Session
                    $currentLang = Session::get('language');
                    // Check RTL: use accessor which returns boolean (rtl != 0)
                    $isRtl = false;
                    if (!empty($currentLang)) {
                        try {
                            // Try to get raw attribute first, fallback to accessor
                            $rtlRaw = method_exists($currentLang, 'getRawOriginal') ? $currentLang->getRawOriginal('rtl') : null;
                            if ($rtlRaw !== null) {
                                $isRtl = ($rtlRaw == 1 || $rtlRaw === true);
                            } else {
                                $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                            }
                        } catch (\Exception $e) {
                            $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                        }
                    }
                    $arrowIcon = $isRtl ? '&#xf0d9;' : '&#xf0da;'; // fa-caret-left for RTL, fa-caret-right for LTR
                @endphp
                <i style="font-size:24px"
                   class="fas toggle-button {{ in_array($category->id, $selected_all_categories) ? 'open' : '' }}">
                   {!! $arrowIcon !!}
                </i>
            @endif
        </div>

        {{-- ✅ Same open/close logic applies recursively --}}
        <div class="subcategories" 
             style="display: {{ in_array($category->id, $selected_all_categories) ? 'block' : 'none' }};">
            @if (!empty($category->subcategories))
                @include('category.treeview', [
                    'categories' => $category->subcategories,
                    'selected_categories' => $selected_categories,
                    'selected_all_categories' => $selected_all_categories
                ])
            @endif
        </div>
    </div>
@endforeach