<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Process auto-enabled pending withdrawals every minute
        $schedule->job(new \App\Jobs\ProcessAutoWithdrawalsJob)
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/auto-withdrawals.log'));

        // Sync withdrawals that were paid at the acquirer but missed the webhook
        $schedule->job(new \App\Jobs\SyncProcessingWithdrawalsJob)
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/withdrawal-sync.log'));

        // Expire pending charges past their expiration time (every 2 minutes)
        $schedule->job(new \App\Jobs\ExpireChargesJob)
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/expire-charges.log'));

        // Health check acquirers every 5 minutes
        $schedule->job(new \App\Infrastructure\Jobs\HealthCheckAcquirersJob)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/acquirer-health.log'));

        // Process pending splits every 5 minutes
        $schedule->job(new \App\Jobs\ProcessPendingSplitsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/pending-splits.log'));

        // Detect abandoned checkouts for recovery messaging (every 5 minutes)
        $schedule->job(new \App\Jobs\DetectAbandonedCheckoutsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/abandoned-checkouts.log'));

        // Sync acquirer nominal names from latest PIX payloads (every hour)
        $schedule->job(new \App\Jobs\SyncAcquirerNominalsJob)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Daily reconciliation at 02:00
        $schedule->job(new \App\Jobs\DailyReconciliationJob)
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/reconciliation.log'));

        // Clear expired cache/tokens daily
        $schedule->command('cache:prune-stale-tags')->daily();

        // Clean old logs and temp files weekly
        $schedule->command('telescope:prune --hours=168')->weekly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
