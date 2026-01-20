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
                'description' => 'Za power korisnike koji žele da maksimiziraju svoj potencijal',
                'price' => 9.99,
                'duration_days' => 30,
                'features' => [
                    'Unlimited Listings',
                    'Priority Support',
                    'Advanced Analytics',
                    'Pro Badge',
                    'Highlighted Listings',
                    'No Ads',
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
                'description' => 'Za vlasnike biznisa i trgovce',
                'price' => 29.99,
                'duration_days' => 30,
                'features' => [
                    'All Pro Features',
                    'Business Profile Page',
                    'Multiple Locations',
                    'Bulk Upload',
                    'Dedicated Account Manager',
                    'API Access',
                    'Custom Branding',
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
            MembershipTier::create($tier);
        }
    }
}
