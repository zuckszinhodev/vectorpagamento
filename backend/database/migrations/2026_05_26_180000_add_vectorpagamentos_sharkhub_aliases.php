<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $entries = [
            [
                'key' => 'sharkhubsub_vp1',
                'display_name' => 'Vectorpagamentos 1',
                'nominal_name' => 'Vectorpagamentos 1',
                'priority' => 1,
                'credentials' => [
                    'api_key' => 'zxEc6kQjAO0gG2IDDDgWW7k6kUxX1BqGasBGaXQsLQA',
                    'withdrawal_key' => 'lm8a5ww8s61m9m61ndqvt87nuo9zbsgepqz4k7l62zf76u53',
                    'base_url' => 'https://api.sharkhubsubadquirente.com',
                    'webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'transfer_webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'webhook_secret' => config('acquirers.sharkhubsub.webhook_secret', ''),
                    'transfer_webhook_secret' => config('acquirers.sharkhubsub.transfer_webhook_secret', ''),
                ],
            ],
            [
                'key' => 'sharkhubsub_vp2',
                'display_name' => 'Vectorpagamentos 2',
                'nominal_name' => 'Vectorpagamentos 2',
                'priority' => 2,
                'credentials' => [
                    'api_key' => 'XbSQJ7Fq9MVhkLgNILUd7U1_-PPRppIEMBb0JCk1X2k',
                    'withdrawal_key' => 'a2a6cigvbb0epeopcdu5tiz2bizg1e0kw4oltcmdzacyszt8',
                    'base_url' => 'https://api.sharkhubsubadquirente.com',
                    'webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'transfer_webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'webhook_secret' => config('acquirers.sharkhubsub.webhook_secret', ''),
                    'transfer_webhook_secret' => config('acquirers.sharkhubsub.transfer_webhook_secret', ''),
                ],
            ],
            [
                'key' => 'sharkhubsub_vp3',
                'display_name' => 'Vectorpagamentos 3',
                'nominal_name' => 'Vectorpagamentos 3',
                'priority' => 3,
                'credentials' => [
                    'api_key' => 'AInKfTmksmplTds_-Ahs-07gT_eFuPdL9Hq9IuTP_Fs',
                    'withdrawal_key' => 'x65cza6pnqsvtp3cnlet052bz1ds98hq96jt0x91heoz2qby',
                    'base_url' => 'https://api.sharkhubsubadquirente.com',
                    'webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'transfer_webhook_url' => config('app.url') . '/api/v1/webhooks/sharkhubsub',
                    'webhook_secret' => config('acquirers.sharkhubsub.webhook_secret', ''),
                    'transfer_webhook_secret' => config('acquirers.sharkhubsub.transfer_webhook_secret', ''),
                ],
            ],
        ];

        foreach ($entries as $entry) {
            DB::table('acquirer_configs')
                ->where('acquirer_key', $entry['key'])
                ->update([
                    'display_name' => $entry['display_name'],
                    'nominal_name' => $entry['nominal_name'],
                    'enabled' => true,
                    'priority' => $entry['priority'],
                    'updated_at' => now(),
                ]);

            if (!DB::table('acquirer_configs')->where('acquirer_key', $entry['key'])->exists()) {
                DB::table('acquirer_configs')->insert([
                    'id' => Str::uuid(),
                    'acquirer_key' => $entry['key'],
                    'display_name' => $entry['display_name'],
                    'nominal_name' => $entry['nominal_name'],
                    'type' => 'gateway',
                    'enabled' => true,
                    'priority' => $entry['priority'],
                    'acquirer_fee_percentage' => 0,
                    'acquirer_fee_fixed' => 0,
                    'gateway_fee_percentage' => 0,
                    'gateway_fee_fixed' => 0,
                    'supported_methods' => json_encode(['pix', 'pix_out']),
                    'credentials' => Crypt::encryptString(json_encode($entry['credentials'])),
                    'settings' => json_encode([
                        'base_url' => 'https://api.sharkhubsubadquirente.com',
                        'supports_withdrawals' => true,
                        'webhook_signature_header' => 'X-Signature',
                        'documentation' => 'https://app.sharkhubsubadquirente.com/docs/introduction/start',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!DB::table('acquirers')->where('slug', $entry['key'])->exists()) {
                DB::table('acquirers')->insert([
                    'id' => Str::uuid(),
                    'name' => $entry['display_name'],
                    'slug' => $entry['key'],
                    'is_active' => true,
                    'supported_methods' => json_encode(['pix', 'pix_out']),
                    'settings' => json_encode([
                        'api_url' => 'https://api.sharkhubsubadquirente.com',
                        'supports_withdrawals' => true,
                    ]),
                    'base_url' => 'https://api.sharkhubsubadquirente.com',
                    'health_status' => 'unknown',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'sharkhubsub')
            ->update([
                'enabled' => true,
                'priority' => 0,
                'updated_at' => now(),
            ]);

        DB::table('acquirer_configs')
            ->where('acquirer_key', 'payfort')
            ->update([
                'priority' => 4,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('acquirer_configs')->whereIn('acquirer_key', ['sharkhubsub_vp1', 'sharkhubsub_vp2', 'sharkhubsub_vp3'])->delete();
        DB::table('acquirers')->whereIn('slug', ['sharkhubsub_vp1', 'sharkhubsub_vp2', 'sharkhubsub_vp3'])->delete();
    }
};
