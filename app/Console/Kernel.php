<?php

namespace App\Console;

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
        \App\Console\Commands\SendGateInData::class,
        \App\Console\Commands\SendGateOutData::class,
        \App\Console\Commands\ConstructFaceTokenData::class,
    ];
    
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // Gate In (tiap 5 menit)
        $schedule->command('visitor:send-gate-in')
            ->between('5:00', '23:00')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->timeout(120);

        // Gate Out (tiap 5 menit)
        $schedule->command('visitor:send-gate-out')
            ->between('5:00', '23:00')
            ->hourly()
            ->withoutOverlapping();

        // Delete Face Tokens (sekali sehari)
        $schedule->command('visitor:construct-face-token-data')
            ->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
