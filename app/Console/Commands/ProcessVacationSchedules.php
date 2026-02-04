<?php

namespace App\Console\Commands;

use App\Models\SellerSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessVacationSchedules extends Command
{
    protected $signature = 'vacation:process-schedules';

    protected $description = 'Auto-activate and deactivate vacation mode based on scheduled dates';

    public function handle()
    {
        $today = now()->startOfDay();
        $activatedCount = 0;
        $deactivatedCount = 0;

        $toActivate = SellerSetting::where('vacation_auto_activate', true)
            ->where('vacation_mode', false)
            ->whereNotNull('vacation_start_date')
            ->whereDate('vacation_start_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('vacation_end_date')
                    ->orWhereDate('vacation_end_date', '>=', $today);
            })
            ->get();

        foreach ($toActivate as $setting) {
            $setting->vacation_mode = true;
            $setting->save();
            $activatedCount++;
            Log::info("Vacation mode activated for user {$setting->user_id}");
        }

        $toDeactivate = SellerSetting::where('vacation_auto_activate', true)
            ->where('vacation_mode', true)
            ->whereNotNull('vacation_end_date')
            ->whereDate('vacation_end_date', '<', $today)
            ->get();

        foreach ($toDeactivate as $setting) {
            $setting->vacation_mode = false;
            $setting->vacation_start_date = null;
            $setting->vacation_end_date = null;
            $setting->vacation_auto_activate = false;
            $setting->save();
            $deactivatedCount++;
            Log::info("Vacation mode deactivated for user {$setting->user_id}");
        }

        $this->info("Vacation schedules processed:");
        $this->info("- Activated: {$activatedCount}");
        $this->info("- Deactivated: {$deactivatedCount}");

        return Command::SUCCESS;
    }
}
