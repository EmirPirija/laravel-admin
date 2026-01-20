<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\BadgeService;

class AwardBadgesToUsers extends Command
{
    protected $signature = 'badges:award {user_id?}';
    protected $description = 'Award badges to users based on their achievements';

    public function handle()
    {
        $badgeService = app(BadgeService::class);
        $userId = $this->argument('user_id');

        if ($userId) {
            // Samo jedan korisnik
            $user = User::find($userId);
            if ($user) {
                $this->info("Checking badges for user: {$user->name}");
                $badgeService->checkAndAwardAllBadges($user);
                $this->info("Done!");
            } else {
                $this->error("User not found!");
            }
        } else {
            // Svi korisnici
            $this->info("Checking badges for all users...");
            $users = User::all();
            
            $progressBar = $this->output->createProgressBar($users->count());
            $progressBar->start();

            foreach ($users as $user) {
                $badgeService->checkAndAwardAllBadges($user);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
            $this->info("Done! All users checked.");
        }
    }
}
