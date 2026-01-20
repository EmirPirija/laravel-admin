<?php

namespace App\Providers;

use App\Services\CachingService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider {
    /**
     * Register services.
     */
    public function register(): void {
        /*** Header File ***/
        View::composer('layouts.topbar', static function (\Illuminate\View\View $view) {
            $languages = CachingService::getLanguages();
            $defaultLanguage = CachingService::getDefaultLanguage();
            
            // Get current language from session or set to default if not set
            $currentLanguage = Session::get('language', $defaultLanguage);
             $settings = CachingService::getSystemSettings();
            $view->with([
                'languages' => $languages,
                 'defaultLanguage' => $defaultLanguage,
                'currentLanguage' => $currentLanguage,
                'settings' => $settings
            ]);
            // $view->with('languages', CachingService::getLanguages() );
        });

        View::composer('layouts.sidebar', static function (\Illuminate\View\View $view) {
            $settings = CachingService::getSystemSettings('company_logo');
            $view->with('company_logo', $settings ?? '');
        });

        View::composer('layouts.main', static function (\Illuminate\View\View $view) {
            $settings = CachingService::getSystemSettings('favicon_icon');
            $view->with('favicon', $settings ?? '');
            $view->with('lang', Session::get('language'));
        });

        View::composer('auth.login', static function (\Illuminate\View\View $view) {
            $favicon_icon = CachingService::getSystemSettings('favicon_icon');
            $company_logo = CachingService::getSystemSettings('company_logo');
            $login_image = CachingService::getSystemSettings('login_image');
            $view->with('company_logo', $company_logo ?? '');
            $view->with('favicon', $favicon_icon ?? '');
            $view->with('login_bg_image', $login_image ?? '');
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {
        //
    }
}
