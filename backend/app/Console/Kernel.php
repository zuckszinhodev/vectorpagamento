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

        // Sync pending payments that were paid at the acquirer but missed the webhook
        $schedule->job(new \App\Jobs\SyncPendingChargesJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/charge-sync.log'));

        // Expire pending charges past their expiration time (every 2 minutes)
        $schedule->job(new \App\Jobs\ExpireChargesJob)
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/expire-charges.log'));

        // Health check acquirers every minute. The job itself decides which
        // acquirers are actually due, based on each one's configured interval,
        // and probes acquirers that are down twice as often so recovery is fast.
        $schedule->job(new \App\Jobs\RunAcquirerHealthChecksJob)
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/acquirer-health.log'));

        // Retention: drop routing telemetry past its configured window.
        $schedule->command('acquirer:prune-telemetry')
            ->dailyAt('04:10')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/acquirer-prune.log'));

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

        // Prune old non-final charges so stale failed/pending/expired records do not accumulate
        $schedule->job(new \App\Jobs\PruneStaleChargesJob)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/prune-stale-charges.log'));

        // Daily reconciliation at 02:00
        $schedule->job(new \App\Jobs\DailyReconciliationJob)
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/reconciliation.log'));

        // Clear expired cache/tokens daily
        $schedule->command('cache:prune-stale-tags')->daily();

        // Clean old logs and temp files weekly
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            $schedule->command('telescope:prune --hours=168')->weekly()->withoutOverlapping();
        }
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
