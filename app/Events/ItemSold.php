<?php

namespace App\Events;

use App\Models\Item;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ItemSold
{
    use Dispatchable, SerializesModels;

    public $item;
    public $seller;
    public $buyer;

    public function __construct(Item $item, $seller, $buyer)
    {
        $this->item = $item;
        $this->seller = $seller;
        $this->buyer = $buyer;
    }
}
