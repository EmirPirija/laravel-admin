@foreach ($categories as $category)
    <div class="category">
        <label>
            <input type="radio" name="selected_category" value="{{ $category->id }}"
                @if($selected_category == $category->id) checked @endif
                @if($category->subcategories->isNotEmpty()) disabled @endif>
            {{ $category->name }}
        </label>
        @if ($category->subcategories->isNotEmpty())
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
            <i class="fas toggle-button" style="font-size: 24px">{!! $arrowIcon !!}</i>
            <div class="subcategories" style="display: none;">
                @include('items.treeview', ['categories' => $category->subcategories, 'selected_category' => $selected_category])
            </div>
        @endif
    </div>
@endforeach
