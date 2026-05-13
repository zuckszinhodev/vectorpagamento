<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Services\PixNominalNameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAcquirerNominalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PixNominalNameService $service): void
    {
        $configs = AcquirerConfig::where('enabled', true)->get();

        foreach ($configs as $config) {
            try {
                $charge = Charge::query()
                    ->where('acquirer', $config->acquirer_key)
                    ->where('payment_method', 'pix')
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->whereNotNull('pix_copy_paste')->where('pix_copy_paste', '!=', '');
                        })->orWhere(function ($q) {
                            $q->whereNotNull('pix_qr_code')->where('pix_qr_code', '!=', '');
                        });
                    })
                    ->latest()
                    ->first();

                if (!$charge) {
                    continue;
                }

                $newName = $service->syncFromCharge($charge);

                if ($newName && $newName !== trim((string) $config->nominal_name)) {
                    Log::info('Acquirer nominal name auto-updated', [
                        'acquirer' => $config->acquirer_key,
                        'old_name' => $config->nominal_name,
                        'new_name' => $newName,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('SyncAcquirerNominalsJob: failed for acquirer', [
                    'acquirer' => $config->acquirer_key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
