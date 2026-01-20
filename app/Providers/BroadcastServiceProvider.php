<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // IZMJENA: Dodajemo 'middleware' => ['auth:sanctum']
        // Ovim govorimo Laravelu: "Provjeri Bearer token za chat, nemoj tražiti sesiju."
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        require base_path('routes/channels.php');
    }
}