<?php

namespace App\Services;

use App\Models\User;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BadgeService
{
    /**
     * Provjeri i dodijeli SVE moguće bedževe korisniku
     */
    public function checkAndAwardAllBadges(User $user)
    {
        $badges = Badge::where('is_active', true)->get();

        foreach ($badges as $badge) {
            try {
                $this->checkAndAwardBadge($user, $badge);
            } catch (\Exception $e) {
                // Ignoriši greške i nastavi sa sljedećim bedžem
                \Log::warning("Error awarding badge {$badge->slug} to user {$user->id}: " . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Provjeri i dodijeli specifičan bedž korisniku
     */
    public function checkAndAwardBadge(User $user, Badge $badge)
    {
        // Provjeri da li korisnik već ima ovaj bedž
        if ($this->userHasBadge($user, $badge)) {
            return false;
        }

        // Provjeri da li korisnik ispunjava kriterije
        if ($this->userMeetsCriteria($user, $badge)) {
            return $this->awardBadge($user, $badge);
        }

        return false;
    }

    /**
     * Provjeri da li korisnik ima bedž
     */
    public function userHasBadge(User $user, Badge $badge)
    {
        return UserBadge::where('user_id', $user->id)
            ->where('badge_id', $badge->id)
            ->exists();
    }

    /**
     * Provjeri da li korisnik ispunjava kriterije za bedž
     */
    protected function userMeetsCriteria(User $user, Badge $badge)
    {
        $criteria = $badge->criteria;

        if (!$criteria) {
            return false;
        }

        try {
            // Items sold (prodato proizvoda)
            if (isset($criteria['items_sold'])) {
                if (!Schema::hasTable('items')) {
                    return false;
                }
                
                $soldCount = Item::where('user_id', $user->id)
                    ->where('status', 'sold')
                    ->count();

                if ($soldCount < $criteria['items_sold']) {
                    return false;
                }
            }

            // Items bought (kupljeno proizvoda)
            if (isset($criteria['items_bought'])) {
                if (!Schema::hasTable('items')) {
                    return false;
                }
                
                $boughtCount = Item::where('sold_to', $user->id)->count();

                if ($boughtCount < $criteria['items_bought']) {
                    return false;
                }
            }

            // Reviews given (ostavljeno recenzija)
            if (isset($criteria['reviews_given'])) {
                if (!Schema::hasTable('seller_reviews')) {
                    return false;
                }
                
                $reviewsCount = DB::table('seller_reviews')
                    ->where('user_id', $user->id)
                    ->count();

                if ($reviewsCount < $criteria['reviews_given']) {
                    return false;
                }
            }

            // Is verified (verifikovan korisnik)
            if (isset($criteria['is_verified']) && $criteria['is_verified'] === true) {
                // Provjeri da li postoji verification_requests tabela
                if (Schema::hasTable('verification_requests')) {
                    $verificationStatus = DB::table('verification_requests')
                        ->where('user_id', $user->id)
                        ->where('status', 'approved')
                        ->exists();

                    if (!$verificationStatus) {
                        return false;
                    }
                } else {
                    // Ako tabela ne postoji, provjeri kolonu is_verified u users tabeli
                    if (Schema::hasColumn('users', 'is_verified')) {
                        if (!$user->is_verified) {
                            return false;
                        }
                    } else {
                        // Ako nema ni kolonu, ignoriši ovaj kriterij
                        return false;
                    }
                }
            }

            // User ID (early adopter - prvi korisnici)
            if (isset($criteria['user_id'])) {
                $condition = $criteria['user_id'];
                
                // Parsiranje uslova tipa "<= 100"
                if (preg_match('/^(<|>|<=|>=|==)\s*(\d+)$/', $condition, $matches)) {
                    $operator = $matches[1];
                    $value = (int)$matches[2];

                    switch ($operator) {
                        case '<':
                            if (!($user->id < $value)) return false;
                            break;
                        case '>':
                            if (!($user->id > $value)) return false;
                            break;
                        case '<=':
                            if (!($user->id <= $value)) return false;
                            break;
                        case '>=':
                            if (!($user->id >= $value)) return false;
                            break;
                        case '==':
                            if (!($user->id == $value)) return false;
                            break;
                    }
                }
            }

            // Total items posted (ukupno objavljenih oglasa)
            if (isset($criteria['items_posted'])) {
                if (!Schema::hasTable('items')) {
                    return false;
                }
                
                $itemsCount = Item::where('user_id', $user->id)->count();

                if ($itemsCount < $criteria['items_posted']) {
                    return false;
                }
            }

            // Rating average (prosječna ocjena)
            if (isset($criteria['rating_average'])) {
                if (!Schema::hasTable('seller_reviews')) {
                    return false;
                }
                
                $avgRating = DB::table('seller_reviews')
                    ->where('seller_id', $user->id)
                    ->avg('ratings');

                if ($avgRating < $criteria['rating_average']) {
                    return false;
                }
            }

            return true;
            
        } catch (\Exception $e) {
            \Log::warning("Error checking criteria for badge {$badge->slug}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dodijeli bedž korisniku
     */
    protected function awardBadge(User $user, Badge $badge)
    {
        UserBadge::create([
            'user_id' => $user->id,
            'badge_id' => $badge->id,
            'earned_at' => now(),
        ]);

        // Dodaj points korisniku (ako bedž nosi points)
        if ($badge->points > 0) {
            try {
                app(PointsService::class)->addPoints(
                    $user,
                    $badge->points,
                    'badge_earned',
                    "Earned badge: {$badge->name}"
                );
            } catch (\Exception $e) {
                // Ako points service ne radi, samo ignoriši
                \Log::warning("Error adding points for badge: " . $e->getMessage());
            }
        }

        // Opcionalno: Pošalji notifikaciju korisniku
        // notification()->send($user, new BadgeEarnedNotification($badge));

        return true;
    }

    /**
     * Ručno dodijeli bedž korisniku (za admin panel)
     */
    public function manuallyAwardBadge(User $user, Badge $badge)
    {
        if ($this->userHasBadge($user, $badge)) {
            return false;
        }

        return $this->awardBadge($user, $badge);
    }

    /**
     * Ukloni bedž od korisnika
     */
    public function removeBadge(User $user, Badge $badge)
    {
        return UserBadge::where('user_id', $user->id)
            ->where('badge_id', $badge->id)
            ->delete();
    }
}
