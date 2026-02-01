@foreach ($categories as $category)
    <div class="category">
        <div class="category-header">
            <label>
                <input type="checkbox"
                       name="{{ $input_name }}[]"
                       value="{{ $category->id }}"
                       {{ in_array($category->id, $selected_categories) ? "checked" : "" }}>
                {{ $category->name }}
            </label>

            @if (!empty($category->subcategories))
                @php
                    $currentLang = Session::get('language');
                    $isRtl = false;
                    if (!empty($currentLang)) {
                        try {
                            $rtlRaw = method_exists($currentLang, 'getRawOriginal') ? $currentLang->getRawOriginal('rtl') : null;
                            if ($rtlRaw !== null) $isRtl = ($rtlRaw == 1 || $rtlRaw === true);
                            else $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                        } catch (\Exception $e) {
                            $isRtl = ($currentLang->rtl == true || $currentLang->rtl === 1);
                        }
                    }
                    $arrowIcon = $isRtl ? '&#xf0d9;' : '&#xf0da;';
                @endphp

                <i style="font-size:24px"
                   class="fas toggle-button {{ in_array($category->id, $selected_all_categories) ? 'open' : '' }}">
                    {!! $arrowIcon !!}
                </i>
            @endif
        </div>

        <div class="subcategories"
             style="display: {{ in_array($category->id, $selected_all_categories) ? 'block' : 'none' }};">
            @if (!empty($category->subcategories))
                @include('category.treeview-bulk', [
                    'categories' => $category->subcategories,
                    'selected_categories' => $selected_categories,
                    'selected_all_categories' => $selected_all_categories,
                    'input_name' => $input_name
                ])
            @endif
        </div>
    </div>
@endforeach
