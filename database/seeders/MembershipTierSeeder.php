<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MembershipTier;

class MembershipTierSeeder extends Seeder
{
    public function run()
    {
        $tiers = [
            [
                'name' => 'LMX Pro',
                'slug' => 'pro',
                'description' => 'Za napredne prodavače koji žele povećati vidljivost i optimizirati ROI',
                'price' => 9.99,
                'duration_days' => 30,
                'features' => [
                    'Neograničen broj oglasa',
                    'Prioritetna podrška',
                    'Napredna analitika',
                    'PRO oznaka na profilu',
                    'Istaknuti oglasi',
                    'Bez reklama',
                ],
                'permissions' => [
                    'unlimited_items' => true,
                    'priority_support' => true,
                    'advanced_analytics' => true,
                    'highlighted_listings' => true,
                    'no_ads' => true,
                ],
                'is_active' => true,
                'order' => 1,
            ],
            [
                'name' => 'LMX Shop',
                'slug' => 'shop',
                'description' => 'Za vlasnike shopova i trgovce koji žele upravljati zalihama i brendingom',
                'price' => 29.99,
                'duration_days' => 30,
                'features' => [
                    'Sve Pro pogodnosti',
                    'Poslovni profil shopa',
                    'Upravljanje zalihama i SKU',
                    'Skupni upload artikala',
                    'Prilagođeni brending',
                    'SHOP oznaka na profilu',
                    'Pristup API-ju',
                ],
                'permissions' => [
                    'unlimited_items' => true,
                    'priority_support' => true,
                    'advanced_analytics' => true,
                    'business_profile' => true,
                    'multiple_locations' => true,
                    'bulk_upload' => true,
                    'api_access' => true,
                    'custom_branding' => true,
                ],
                'is_active' => true,
                'order' => 2,
            ],
        ];

        foreach ($tiers as $tier) {
            MembershipTier::updateOrCreate(
                ['slug' => $tier['slug']],
                $tier
            );
        }
    }
}
