<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Badge;
use Illuminate\Support\Facades\Schema;

class BadgeSeeder extends Seeder
{
    public function run()
    {
        $badges = [];

        // Dodaj samo badge-ove koji koriste postojeće tabele
        if (Schema::hasTable('items')) {
            $badges[] = [
                'name' => 'Super Seller',
                'slug' => 'super-seller',
                'description' => 'Prodao/la 100+ proizvoda',
                'type' => 'achievement',
                'points' => 500,
                'order' => 1,
                'criteria' => ['items_sold' => 100],
            ];

            $badges[] = [
                'name' => 'Trusted Buyer',
                'slug' => 'trusted-buyer',
                'description' => 'Kupio/la 50+ proizvoda',
                'type' => 'achievement',
                'points' => 300,
                'order' => 2,
                'criteria' => ['items_bought' => 50],
            ];

            $badges[] = [
                'name' => 'First Sale',
                'slug' => 'first-sale',
                'description' => 'Prva prodaja!',
                'type' => 'milestone',
                'points' => 50,
                'order' => 3,
                'criteria' => ['items_sold' => 1],
            ];

            $badges[] = [
                'name' => 'Listing Master',
                'slug' => 'listing-master',
                'description' => 'Objavio/la 50+ oglasa',
                'type' => 'achievement',
                'points' => 200,
                'order' => 7,
                'criteria' => ['items_posted' => 50],
            ];
        }

        // Early Adopter (ne zavisi od tabela)
        $badges[] = [
            'name' => 'Early Adopter',
            'slug' => 'early-adopter',
            'description' => 'Jedan od prvih korisnika',
            'type' => 'special',
            'points' => 200,
            'order' => 5,
            'criteria' => ['user_id' => '<= 100'],
        ];

        // Review badge samo ako postoji seller_reviews tabela
        if (Schema::hasTable('seller_reviews')) {
            $badges[] = [
                'name' => 'Review Master',
                'slug' => 'review-master',
                'description' => 'Ostavio/la 50+ recenzija',
                'type' => 'achievement',
                'points' => 250,
                'order' => 6,
                'criteria' => ['reviews_given' => 50],
            ];
        }

        // Verified badge - opciono, komentirano dok ne napraviš verification sistem
        /*
        $badges[] = [
            'name' => 'Verified User',
            'slug' => 'verified-user',
            'description' => 'Verifikovan korisnik',
            'type' => 'special',
            'points' => 100,
            'order' => 4,
            'criteria' => ['is_verified' => true],
        ];
        */

        foreach ($badges as $badge) {
            Badge::updateOrCreate(
                ['slug' => $badge['slug']],
                $badge
            );
        }

        $this->command->info('Badges seeded successfully! Total: ' . count($badges));
    }
}
