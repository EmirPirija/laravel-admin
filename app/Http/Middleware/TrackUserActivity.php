<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB; // Važno!
use App\Events\UserOnlineStatus;
use Carbon\Carbon;

class TrackUserActivity
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // --- 1. LIVE ONLINE STATUS (CACHE) ---
            // Ovo služi za zelenu tačkicu. Traje 5 minuta.
            $cacheKey = 'user-online-' . $user->id;
            $wasOnline = Cache::has($cacheKey);
            
            Cache::put($cacheKey, true, now()->addMinutes(5));
            
            // Ako korisnik nije bio online do sad, javi socketima (opciono, u try-catch)
            if (!$wasOnline) {
                try {
                    broadcast(new UserOnlineStatus($user->id, true))->toOthers();
                } catch (\Exception $e) {
                    // Ignorišemo grešku ako socket server (Reverb/Pusher) nije dostupan
                }
            }

            // --- 2. LAST SEEN (DATABASE) ---
            // Ovo služi za ispis "Viđen prije 10 min".
            // Ažuriramo bazu samo ako je prošlo više od 5 minuta od zadnjeg upisa
            // da ne "udaram" bazu na svaki klik.
            
            $shouldUpdate = false;
            
            if (!$user->last_seen) {
                $shouldUpdate = true;
            } else {
                // Parsiramo datum jer je u modelu castovan u datetime
                $lastSeen = $user->last_seen; 
                if ($lastSeen->diffInMinutes(now()) >= 5) {
                    $shouldUpdate = true;
                }
            }

            if ($shouldUpdate) {
                // Koristimo DB fasadu direktno jer je brža i ne triggeruje 'updated_at'
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['last_seen' => now()]);
            }
        }
        
        return $next($request);
    }
}