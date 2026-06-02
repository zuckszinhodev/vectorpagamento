<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StaleChargePruner;
use Illuminate\Console\Command;

class PruneStaleChargesCommand extends Command
{
    protected $signature = 'charges:prune-stale';

    protected $description = 'Remove cobranças antigas com status failed, pending, processing e expired';

    public function handle(StaleChargePruner $pruner): int
    {
        $deleted = $pruner->prune();

        $this->info("Limpeza de cobranças antigas concluída. Removidas: {$deleted}.");

        return self::SUCCESS;
    }
}
