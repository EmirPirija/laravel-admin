<?php
 
namespace App\Console;
 
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\Item;
 
class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\CustomAutoTranslate::class,
        \App\Console\Commands\CustomTranslateMissing::class,
    ];
 
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Postojeći taskovi
        $schedule->command('notify:expiring-items')->dailyAt('09:00');
        $schedule->command('notify:expiring-packages')->daily();
 
        // NOVO: Objavi zakazane oglase
        $schedule->call(function () {
            Item::where('status', 'scheduled')
                ->where('scheduled_at', '<=', now())
                ->update([
                    'status' => 'approved',
                    'scheduled_at' => null
                ]);
        })->everyMinute()->name('publish-scheduled-ads')->withoutOverlapping();
    }
 
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
 
        require base_path('routes/console.php');
    }
}
