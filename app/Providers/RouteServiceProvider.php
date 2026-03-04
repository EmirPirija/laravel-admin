<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider {
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot() {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting() {
        RateLimiter::for('api', static function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('public-location', static function (Request $request) {
            $scope = 'coordinates';
            if ($request->filled('search')) {
                $scope = 'search';
            } elseif ($request->filled('place_id')) {
                $scope = 'place_id';
            }

            $key = implode(':', [
                'public-location',
                $scope,
                $request->user()?->id ?: $request->ip(),
            ]);

            return match ($scope) {
                'search' => Limit::perMinute(100)->by($key),
                'place_id' => Limit::perMinute(120)->by($key),
                default => Limit::perMinute(80)->by($key),
            };
        });

        RateLimiter::for('verification-status', static function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(45)->by('verification-status:' . $actor);
        });
    }
}
