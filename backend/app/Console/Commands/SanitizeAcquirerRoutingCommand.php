<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Acquirer\Entities\Acquirer;
use App\Models\AccountAcquirer;
use App\Models\AcquirerConfig;
use App\Services\AcquirerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SanitizeAcquirerRoutingCommand extends Command
{
    protected $signature = 'acquirers:sanitize-routing
        {--fix : Aplica as correcoes automaticamente}
        {--account= : Limita a uma conta especifica}';

    protected $description = 'Audita e saneia inconsistencias de roteamento, prioridades e configs de adquirentes';

    public function handle(): int
    {
        $applyFixes = (bool) $this->option('fix');
        $accountId = $this->option('account');

        $this->info($applyFixes
            ? 'Saneando configuracoes de adquirentes...'
            : 'Auditando configuracoes de adquirentes (dry-run)...');

        $changes = [
            'acquirer_rows_created' => 0,
            'alias_configs_disabled' => 0,
            'global_priorities_normalized' => 0,
            'account_priorities_normalized' => 0,
            'account_assignments_disabled' => 0,
        ];

        AcquirerConfig::clearCache();
        AcquirerFactory::reset();

        $configs = AcquirerConfig::query()
            ->orderBy('priority')
            ->orderBy('acquirer_key')
            ->get();

        $this->line('');
        $this->info('Configs habilitadas com problema objetivo:');

        foreach ($configs as $config) {
            if (!$config->enabled) {
                continue;
            }

            $hasDbCreds = $config->getDecryptedCredentials() !== null;
            $hasValidCreds = $config->hasValidCredentials();
            $isAlias = AcquirerConfig::normalizeAcquirerKey($config->acquirer_key) !== $config->acquirer_key;

            if (!$hasValidCreds) {
                $this->warn("- {$config->acquirer_key}: habilitada sem credenciais validas");
            }

            if ($isAlias && !$hasDbCreds) {
                $this->warn("- {$config->acquirer_key}: alias habilitado sem credencial DB descriptografavel");

                if ($applyFixes) {
                    $config->forceFill(['enabled' => false])->save();
                    $changes['alias_configs_disabled']++;
                }
            }

            $acquirer = Acquirer::query()->where('slug', $config->acquirer_key)->first();
            if (!$acquirer) {
                $this->warn("- {$config->acquirer_key}: sem registro correspondente em acquirers");

                if ($applyFixes) {
                    Acquirer::create([
                        'id' => (string) Str::uuid(),
                        'name' => $config->display_name ?: $config->acquirer_key,
                        'slug' => $config->acquirer_key,
                        'is_active' => $config->enabled,
                        'supported_methods' => $config->supported_methods ?? ['pix'],
                        'settings' => $config->settings ?? [],
                    ]);
                    $changes['acquirer_rows_created']++;
                }
            }
        }

        $enabledConfigs = AcquirerConfig::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('acquirer_key')
            ->get();

        $duplicateGlobalPriorities = $enabledConfigs
            ->groupBy(fn (AcquirerConfig $config) => (string) $config->priority)
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicateGlobalPriorities->isNotEmpty()) {
            $this->line('');
            $this->warn('Prioridades globais duplicadas encontradas:');
            foreach ($duplicateGlobalPriorities as $priority => $group) {
                $this->warn("- prioridade {$priority}: " . $group->pluck('acquirer_key')->implode(', '));
            }

            if ($applyFixes) {
                DB::transaction(function () use ($enabledConfigs, &$changes): void {
                    foreach ($enabledConfigs->values() as $index => $config) {
                        $config->forceFill(['priority' => $index])->save();
                    }
                    $changes['global_priorities_normalized'] = $enabledConfigs->count();
                });
            }
        }

        $assignmentsQuery = AccountAcquirer::query()
            ->where('account_acquirers.is_active', true)
            ->join('acquirers', 'acquirers.id', '=', 'account_acquirers.acquirer_id')
            ->select('account_acquirers.*', 'acquirers.slug as acquirer_slug')
            ->orderBy('account_acquirers.account_id')
            ->orderBy('account_acquirers.priority')
            ->orderBy('acquirers.slug');

        if (is_string($accountId) && $accountId !== '') {
            $assignmentsQuery->where('account_acquirers.account_id', $accountId);
        }

        $assignments = $assignmentsQuery->get();
        $groupedAssignments = $assignments->groupBy('account_id');

        $this->line('');
        $this->info('Problemas em account_acquirers:');

        foreach ($groupedAssignments as $sellerAccountId => $sellerAssignments) {
            $priorityGroups = $sellerAssignments->groupBy(fn ($assignment) => (string) $assignment->priority);
            $hasDuplicatePriority = $priorityGroups->contains(fn ($group) => $group->count() > 1);

            if ($hasDuplicatePriority) {
                foreach ($priorityGroups as $priority => $group) {
                    if ($group->count() > 1) {
                        $this->warn("- {$sellerAccountId}: prioridade {$priority} duplicada em " . $group->pluck('acquirer_slug')->implode(', '));
                    }
                }

                if ($applyFixes) {
                    DB::transaction(function () use ($sellerAssignments, &$changes): void {
                        foreach ($sellerAssignments->values() as $index => $assignment) {
                            AccountAcquirer::query()->whereKey($assignment->id)->update(['priority' => $index]);
                        }
                        $changes['account_priorities_normalized']++;
                    });
                }
            }

            foreach ($sellerAssignments as $assignment) {
                $config = AcquirerConfig::query()->where('acquirer_key', $assignment->acquirer_slug)->first();
                $hasUsableConfig = $config && $config->enabled && $config->hasValidCredentials();

                if ($hasUsableConfig) {
                    continue;
                }

                $this->warn("- {$sellerAccountId}: vinculo ativo para {$assignment->acquirer_slug} sem config habilitada/valida");

                if ($applyFixes) {
                    DB::table('account_acquirers')
                        ->where('id', $assignment->id)
                        ->update(['is_active' => false, 'updated_at' => now()]);
                    $changes['account_assignments_disabled']++;
                }
            }
        }

        AcquirerConfig::clearCache();
        AcquirerFactory::reset();

        $this->line('');
        if ($applyFixes) {
            $this->info('Correcoes aplicadas:');
            foreach ($changes as $label => $count) {
                $this->line("- {$label}: {$count}");
            }
        } else {
            $this->info('Dry-run concluido. Use --fix para aplicar as correcoes detectadas.');
        }

        return self::SUCCESS;
    }
}
