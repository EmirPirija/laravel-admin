<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ExpiringItemService;

class NotifyExpiringItems extends Command
{
    protected $signature = 'notify:expiring-items';
    protected $description = 'Send notifications for items expiring in 2 days.';

    protected $expiringItemService;

    /**
     * Inject the ExpiringItemService
     */
    public function __construct(ExpiringItemService $expiringItemService)
    {
        parent::__construct();
        $this->expiringItemService = $expiringItemService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->expiringItemService->notifyExpiringItems();
        $this->info('Expiring Advertisement notifications sent successfully.');
    }
}
