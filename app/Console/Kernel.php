<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\FetchDahuaDataChannel;

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
            ->runInBackground()
            ->withoutOverlapping();

        // Gate Out (tiap 5 menit)
        $schedule->command('visitor:send-gate-out')
            ->between('5:00', '23:00')
            ->hourly()
            ->runInBackground()
            ->withoutOverlapping();

        // Delete Face Tokens (sekali sehari)
        $schedule->command('visitor:construct-face-token-data')
            ->daily();

        $channels = [
            [1, 'in', 'Gate-In-A'],
            [2, 'in', 'Gate-In-B'],
            [3, 'in', 'Gate-In-C'],
            [4, 'in', 'Gate-In-D'],
            [5, 'in', 'Gate-In-E'],
            [6, 'in', 'Gate-In-F'],
            [7, 'out', 'Gate-Out-A'],
            [8, 'in', 'Gate-In-G'],
        ];

        foreach ($channels as [$ch, $label, $gate]) {
            $schedule->job(new FetchDahuaDataChannel($ch, $label, $gate))
                ->everyFiveMinutes()
                ->between('5:00', '23:00')
                ->withoutOverlapping();
        }
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
