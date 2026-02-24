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

        // NOVO: Procesira zakazane postove na društvene mreže
$schedule->command('social:process-scheduled-posts')
->everyMinute()
->name('process-scheduled-social-posts')
->withoutOverlapping();

$schedule->command('vacation:process-schedules')
    ->dailyAt('00:05')
    ->name('process-vacation-schedules')
    ->withoutOverlapping();


        // Postojeći taskovi
        $schedule->command('notify:expiring-items')->dailyAt('09:00');
        $schedule->command('notify:expiring-packages')->daily();
        $schedule->command('firebase:prune-stale-users')
            ->everyFifteenMinutes()
            ->name('firebase-prune-stale-users')
            ->withoutOverlapping();
 
        // NOVO: Objavi zakazane oglase
        $schedule->call(function () {
            Item::where('status', 'scheduled')
                ->where('scheduled_at', '<=', now('UTC'))
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
