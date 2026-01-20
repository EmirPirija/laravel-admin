<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ExpiringItemService;

class NotifyExpiringPackages extends Command
{
    protected $signature = 'notify:expiring-packages';
    protected $description = 'Send notifications for expiring packages.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(ExpiringItemService $expiringItemService)
    {
        $expiringItemService->notifyExpiringPackages();
        $this->info('Expiring packages notifications sent successfully.');
    }
}
