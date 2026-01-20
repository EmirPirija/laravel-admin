<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPoints;
use App\Models\PointsHistory;

class PointsService
{
    // Level thresholds (koliko points treba za svaki level)
    protected $levelThresholds = [
        1 => 0,
        2 => 100,
        3 => 250,
        4 => 500,
        5 => 1000,
        6 => 2000,
        7 => 4000,
        8 => 8000,
        9 => 15000,
        10 => 25000,
    ];

    protected $levelNames = [
        1 => 'Beginner',
        2 => 'Novice',
        3 => 'Intermediate',
        4 => 'Advanced',
        5 => 'Expert',
        6 => 'Master',
        7 => 'Legend',
        8 => 'Champion',
        9 => 'Elite',
        10 => 'Grandmaster',
    ];

    /**
     * Dodaj points korisniku
     */
    public function addPoints(User $user, int $points, string $action, string $description = null, $referenceType = null, $referenceId = null)
    {
        // Dohvati ili kreiraj user points record
        $userPoints = UserPoints::firstOrCreate(
            ['user_id' => $user->id],
            [
                'total_points' => 0,
                'level' => 1,
                'level_name' => $this->levelNames[1],
                'points_to_next_level' => $this->levelThresholds[2],
                'current_level_points' => 0,
            ]
        );

        // Dodaj points
        $userPoints->total_points += $points;
        $userPoints->current_level_points += $points;

        // Provjeri da li je korisnik prešao na novi level
        $this->checkLevelUp($userPoints);

        $userPoints->save();

        // Snimi u historiju
        PointsHistory::create([
            'user_id' => $user->id,
            'points' => $points,
            'action' => $action,
            'description' => $description ?? "Earned {$points} points from {$action}",
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return $userPoints;
    }

    /**
     * Provjeri da li korisnik treba da pređe na novi level
     */
    protected function checkLevelUp(UserPoints $userPoints)
    {
        $currentLevel = $userPoints->level;
        $nextLevel = $currentLevel + 1;

        // Provjeri da li postoji sljedeći level
        if (!isset($this->levelThresholds[$nextLevel])) {
            return; // Maksimalni level dostignut
        }

        $pointsNeeded = $this->levelThresholds[$nextLevel];

        // Da li ima dovoljno points za level up?
        if ($userPoints->total_points >= $pointsNeeded) {
            $userPoints->level = $nextLevel;
            $userPoints->level_name = $this->levelNames[$nextLevel];
            $userPoints->current_level_points = $userPoints->total_points - $pointsNeeded;

            // Postavi points potrebne za sljedeći level
            if (isset($this->levelThresholds[$nextLevel + 1])) {
                $userPoints->points_to_next_level = $this->levelThresholds[$nextLevel + 1] - $pointsNeeded;
            } else {
                $userPoints->points_to_next_level = 0; // Max level
            }

            // Opcionalno: Pošalji notifikaciju za level up
            // notification()->send($user, new LevelUpNotification($nextLevel));
        }
    }

    /**
     * Oduzmi points od korisnika (za penalizacije)
     */
    public function removePoints(User $user, int $points, string $action, string $description = null)
    {
        $userPoints = UserPoints::where('user_id', $user->id)->first();

        if (!$userPoints) {
            return;
        }

        $userPoints->total_points = max(0, $userPoints->total_points - $points);
        $userPoints->current_level_points = max(0, $userPoints->current_level_points - $points);
        $userPoints->save();

        // Snimi u historiju (negativni points)
        PointsHistory::create([
            'user_id' => $user->id,
            'points' => -$points,
            'action' => $action,
            'description' => $description ?? "Lost {$points} points from {$action}",
        ]);
    }
}
