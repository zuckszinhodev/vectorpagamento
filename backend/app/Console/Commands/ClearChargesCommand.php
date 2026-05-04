<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearChargesCommand extends Command
{
    protected $signature = 'charges:clear {--force : Pular confirmação}';
    protected $description = 'Remove todas as cobranças e dados relacionados do banco de dados';

    public function handle(): int
    {
        $chargesCount = DB::table('charges')->count();
        $transactionsCount = DB::table('transactions')->count();
        $splitsCount = DB::table('splits')->count();

        $this->warn("Serão removidos:");
        $this->line("  - {$chargesCount} cobranças");
        $this->line("  - {$transactionsCount} transações");
        $this->line("  - {$splitsCount} splits");

        if (!$this->option('force') && !$this->confirm('Tem certeza que deseja continuar?')) {
            $this->info('Operação cancelada.');
            return 0;
        }

        DB::statement('SET session_replication_role = replica');

        DB::table('splits')->truncate();
        DB::table('transactions')->truncate();
        DB::table('charges')->truncate();

        DB::statement('SET session_replication_role = DEFAULT');

        $this->info("Removidos: {$chargesCount} cobranças, {$transactionsCount} transações, {$splitsCount} splits.");

        return 0;
    }
}
