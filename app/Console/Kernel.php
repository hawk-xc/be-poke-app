<?php

namespace App\Console;

use App\Jobs\Visitor\SendGateInData;
use App\Jobs\Visitor\SendGateOutData;
use App\Jobs\Visitor\DeleteFaceTokenData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\SendEntryBatch::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // machine learning get entry cron
        // $schedule->command('ml:get-entry')
        //     ->hourly()
        //     ->between('5:00', '23:00')
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/ml_get_entry.log'));

        $schedule->command('visitor:send-gate-in')->between('5:00', '23:00')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('visitor:send-gate-out')->between('5:00', '23:00')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
        $schedule->command('visitor:delete-face-tokens')->daily();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}