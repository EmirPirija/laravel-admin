<?php

namespace App\Listeners;

use App\Events\ItemSold;
use App\Services\BadgeService;
use App\Services\PointsService;

class AwardBadgesOnItemSold
{
    protected $badgeService;
    protected $pointsService;

    public function __construct(BadgeService $badgeService, PointsService $pointsService)
    {
        $this->badgeService = $badgeService;
        $this->pointsService = $pointsService;
    }

    public function handle(ItemSold $event)
    {
        // Dodaj points prodavcu
        $this->pointsService->addPoints(
            $event->seller,
            50, // 50 points za prodaju
            'item_sold',
            "Sold item: {$event->item->name}",
            'item',
            $event->item->id
        );

        // Provjeri bedževe za prodavca (First Sale, Super Seller, itd.)
        $this->badgeService->checkAndAwardAllBadges($event->seller);

        // Dodaj points kupcu (manje points)
        if ($event->buyer) {
            $this->pointsService->addPoints(
                $event->buyer,
                10, // 10 points za kupovinu
                'item_bought',
                "Bought item: {$event->item->name}",
                'item',
                $event->item->id
            );

            // Provjeri bedževe za kupca (Trusted Buyer)
            $this->badgeService->checkAndAwardAllBadges($event->buyer);
        }
    }
}
