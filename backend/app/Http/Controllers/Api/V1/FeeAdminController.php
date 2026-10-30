<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcquirerConfig;
use App\Models\SystemSetting;
use App\Services\SystemFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FeeAdminController extends Controller
{
    private const PROFIT_PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];

    public function __construct(private readonly SystemFeeService $fees)
    {
    }

    public function index(): JsonResponse
    {
        $rates = [];
        foreach (SystemFeeService::METHODS as $method) {
            $rates[$method] = $this->fees->rate($method) ?? ['percentage' => 0.0, 'fixed' => 0.0];
        }

        $acquirers = AcquirerConfig::orderByDesc('enabled')->orderBy('priority')->get()->map(fn (AcquirerConfig $config) => [
            'key' => $config->acquirer_key,
            'name' => $config->display_name,
            'enabled' => (bool) $config->enabled,
            'cash_in_percentage' => (float) $config->acquirer_fee_percentage,
            'cash_in_fixed' => (float) $config->acquirer_fee_fixed,
            'cash_out_percentage' => (float) $config->acquirer_fee_pix_out_percentage,
            'cash_out_fixed' => (float) $config->acquirer_fee_pix_out_fixed,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'rates' => $rates,
                'withdrawal' => [
                    'pix_fee' => (int) SystemSetting::get('standard_withdrawal_fee_pix', 0),
                    'bank_fee' => (int) SystemSetting::get('standard_withdrawal_fee_bank', 200),
                    'extra_enabled' => (bool) SystemSetting::get('instant_withdrawal_enabled', false),
                    'extra_type' => SystemSetting::get('instant_withdrawal_fee_type', 'percentage'),
                    'extra_value' => (float) SystemSetting::get('instant_withdrawal_fee_value', 2.5),
                ],
                'acquirers' => $acquirers,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'withdrawal.pix_fee' => ['required', 'integer', 'min:0'],
            'withdrawal.bank_fee' => ['required', 'integer', 'min:0'],
            'withdrawal.extra_enabled' => ['required', 'boolean'],
            'withdrawal.extra_type' => ['required', 'in:percentage,fixed'],
            'withdrawal.extra_value' => ['required', 'numeric', 'min:0'],
        ];
        foreach (SystemFeeService::METHODS as $method) {
            $rules["rates.{$method}.percentage"] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules["rates.{$method}.fixed"] = ['required', 'numeric', 'min:0'];
        }

        $validated = $request->validate($rules);

        foreach (SystemFeeService::METHODS as $method) {
            foreach (['percentage', 'fixed'] as $part) {
                $this->store("system_fee_{$method}_{$part}", (string) (float) $validated['rates'][$method][$part], 'string');
            }
        }

        $withdrawal = $validated['withdrawal'];
        $this->store('standard_withdrawal_fee_pix', (string) $withdrawal['pix_fee'], 'integer');
        $this->store('standard_withdrawal_fee_bank', (string) $withdrawal['bank_fee'], 'integer');
        $this->store('instant_withdrawal_enabled', $withdrawal['extra_enabled'] ? 'true' : 'false', 'boolean');
        $this->store('instant_withdrawal_fee_type', $withdrawal['extra_type'], 'string');
        $this->store('instant_withdrawal_fee_value', (string) $withdrawal['extra_value'], 'string');

        return $this->index();
    }

    public function profit(Request $request): JsonResponse
    {
        $days = self::PROFIT_PERIODS[$request->input('period', '30d')] ?? null;

        return response()->json([
            'success' => true,
            'data' => $this->fees->profitReport($days ? now()->subDays($days)->startOfDay() : null),
        ]);
    }

    private function store(string $key, string $value, string $type): void
    {
        $setting = SystemSetting::firstOrNew(['key' => $key]);
        $setting->value = $value;
        $setting->type = $setting->type ?: $type;
        $setting->group = $setting->group ?: 'fees';
        $setting->description = $setting->description ?: $key;
        $setting->save();

        Cache::forget("system_setting_{$key}");
    }
}
