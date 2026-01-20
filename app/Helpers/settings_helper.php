<?php

use App\Models\Language;
use Illuminate\Support\Facades\Session;

function current_language() {
    if (Session::get('language') == 'en' || Session::get('language') == 'fr') {
        $lang = Session::get('language');
        Session::put('language', $lang);
        Session::put('locale', $lang);
        app()->setLocale(Session::get('locale'));
    } else {
        $lang = 'en';
        Session::put('language', $lang);
        Session::put('locale', $lang);
        app()->setLocale(Session::get('locale'));
    }
}

function get_language() {
    return Language::get();
}
