<?php

namespace App\Services;

use App\Models\Category;

class RootCategoryIconService
{
    /**
     * Unified icon set for root categories by slug.
     */
    private const ROOT_ICON_MAP = [
        'vozila' => 'category-icons/root/vozila.svg',
        'nekretnine' => 'category-icons/root/nekretnine.svg',
        'mobiteli-i-oprema' => 'category-icons/root/mobiteli-i-oprema.svg',
        'racunari-i-oprema' => 'category-icons/root/racunari-i-oprema.svg',
        'tehnika' => 'category-icons/root/tehnika.svg',
        'video-igre-i-konzole' => 'category-icons/root/video-igre-i-konzole.svg',
        'moj-dom' => 'category-icons/root/moj-dom.svg',
        'muzika-i-audio-oprema' => 'category-icons/root/muzika-i-audio-oprema.svg',
        'literatura' => 'category-icons/root/literatura.svg',
        'umjetnost-i-dekoracija' => 'category-icons/root/umjetnost-i-dekoracija.svg',
        'kolekcionarstvo' => 'category-icons/root/kolekcionarstvo.svg',
        'antikviteti' => 'category-icons/root/antikviteti.svg',
        'karte-i-ulaznice' => 'category-icons/root/karte-i-ulaznice.svg',
        'hrana-i-pice' => 'category-icons/root/hrana-i-pice.svg',
        'bebe' => 'category-icons/root/bebe.svg',
    ];

    private const DEFAULT_ROOT_ICON = 'category-icons/root/default.svg';

    public static function resolveRootIconUrl(Category $category): ?string
    {
        if (! empty($category->parent_category_id)) {
            return null;
        }

        $slug = (string) ($category->slug ?? '');
        $relativePath = self::ROOT_ICON_MAP[$slug] ?? self::DEFAULT_ROOT_ICON;

        return url($relativePath);
    }
}

