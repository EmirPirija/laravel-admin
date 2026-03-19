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
                'name' => 'Super prodavač',
                'slug' => 'super-prodavac',
                'description' => 'Prodao/la 100+ proizvoda',
                'type' => 'achievement',
                'points' => 500,
                'order' => 1,
                'criteria' => ['items_sold' => 100],
            ];

            $badges[] = [
                'name' => 'Pouzdani kupac',
                'slug' => 'pouzdani-kupac',
                'description' => 'Kupio/la 50+ proizvoda',
                'type' => 'achievement',
                'points' => 300,
                'order' => 2,
                'criteria' => ['items_bought' => 50],
            ];

            $badges[] = [
                'name' => 'Prva prodaja',
                'slug' => 'prva-prodaja',
                'description' => 'Prva uspješna prodaja!',
                'type' => 'milestone',
                'points' => 50,
                'order' => 3,
                'criteria' => ['items_sold' => 1],
            ];

            $badges[] = [
                'name' => 'Majstor oglasa',
                'slug' => 'majstor-oglasa',
                'description' => 'Objavio/la 50+ oglasa',
                'type' => 'achievement',
                'points' => 200,
                'order' => 7,
                'criteria' => ['items_posted' => 50],
            ];
        }

        // Rani korisnik (ne zavisi od tabela)
        $badges[] = [
            'name' => 'Rani korisnik',
            'slug' => 'rani-korisnik',
            'description' => 'Jedan od prvih korisnika platforme',
            'type' => 'special',
            'points' => 200,
            'order' => 5,
            'criteria' => ['user_id' => '<= 100'],
        ];

        // Recenzija badge samo ako postoji seller_reviews tabela
        if (Schema::hasTable('seller_reviews')) {
            $badges[] = [
                'name' => 'Majstor recenzija',
                'slug' => 'majstor-recenzija',
                'description' => 'Ostavio/la 50+ recenzija',
                'type' => 'achievement',
                'points' => 250,
                'order' => 6,
                'criteria' => ['reviews_given' => 50],
            ];
        }

        foreach ($badges as $badge) {
            Badge::updateOrCreate(
                ['slug' => $badge['slug']],
                $badge
            );
        }

        $this->command->info('Bedževi uspješno uneseni! Ukupno: ' . count($badges));
    }
}
