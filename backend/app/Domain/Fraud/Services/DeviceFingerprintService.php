<?php

namespace App\Domain\Fraud\Services;

use Illuminate\Support\Facades\DB;

class DeviceFingerprintService
{
    /**
     * Analyze device fingerprint
     */
    public function analyzeDevice(?string $fingerprintHash, ?string $customerEmail = null): array
    {
        if (!$fingerprintHash) {
            return ['score' => 0, 'reason' => 'no_fingerprint'];
        }

        // Get or create device record
        $device = DB::table('device_fingerprints')
            ->where('fingerprint_hash', $fingerprintHash)
            ->first();

        if (!$device) {
            // New device - low risk by default
            return [
                'score' => 10,
                'reason' => 'new_device',
                'is_new' => true,
                'trust_level' => 'unknown',
            ];
        }

        $score = 0;
        $flags = [];

        // Check trust level
        if ($device->trust_level === 'blocked') {
            $score += 50;
            $flags[] = 'blocked_device';
        } elseif ($device->trust_level === 'suspicious') {
            $score += 30;
            $flags[] = 'suspicious_device';
        } elseif ($device->trust_level === 'trusted') {
            $score -= 10; // Reduce risk for trusted devices
        }

        // Check chargeback history
        if ($device->chargeback_count > 0) {
            $score += $device->chargeback_count * 15;
            $flags[] = "chargebacks:{$device->chargeback_count}";
        }

        // Check decline rate
        if ($device->transaction_count > 0) {
            $declineRate = ($device->declined_count / $device->transaction_count) * 100;
            if ($declineRate > 50) {
                $score += 20;
                $flags[] = 'high_decline_rate';
            }
        }

        // Check if device is being used by multiple customers (velocity)
        $customerCount = DB::table('charges')
            ->where('device_fingerprint', $fingerprintHash)
            ->distinct('customer_email')
            ->count();

        if ($customerCount > 5) {
            $score += 25;
            $flags[] = "shared_device:{$customerCount}_customers";
        }

        // Check if customer email matches previous uses
        if ($customerEmail && $device->customer_email && $device->customer_email !== $customerEmail) {
            $score += 15;
            $flags[] = 'email_mismatch';
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
            'trust_level' => $device->trust_level,
            'transaction_count' => $device->transaction_count,
            'chargeback_count' => $device->chargeback_count,
            'is_new' => false,
        ];
    }

    /**
     * Create or update device fingerprint
     */
    public function recordDevice(
        string $fingerprintHash,
        string $ipAddress,
        array $deviceData,
        ?string $customerEmail = null
    ): void {
        $existing = DB::table('device_fingerprints')
            ->where('fingerprint_hash', $fingerprintHash)
            ->first();

        if ($existing) {
            DB::table('device_fingerprints')
                ->where('fingerprint_hash', $fingerprintHash)
                ->update([
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('device_fingerprints')->insert([
                'id' => \Str::uuid(),
                'fingerprint_hash' => $fingerprintHash,
                'customer_email' => $customerEmail,
                'device_data' => json_encode($deviceData),
                'ip_address' => $ipAddress,
                'transaction_count' => 0,
                'declined_count' => 0,
                'chargeback_count' => 0,
                'total_amount' => 0,
                'trust_level' => 'unknown',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Update device stats after transaction
     */
    public function updateDeviceStats(
        string $fingerprintHash,
        bool $approved,
        float $amount,
        bool $isChargeback = false
    ): void {
        $updates = [
            'transaction_count' => DB::raw('transaction_count + 1'),
            'total_amount' => DB::raw('total_amount + ' . (float)$amount),
            'updated_at' => now(),
        ];

        if (!$approved) {
            $updates['declined_count'] = DB::raw('declined_count + 1');
        }

        if ($isChargeback) {
            $updates['chargeback_count'] = DB::raw('chargeback_count + 1');
            $updates['trust_level'] = 'suspicious';
        }

        DB::table('device_fingerprints')
            ->where('fingerprint_hash', $fingerprintHash)
            ->update($updates);
    }

    /**
     * Block a device
     */
    public function blockDevice(string $fingerprintHash, string $reason): void
    {
        DB::table('device_fingerprints')
            ->where('fingerprint_hash', $fingerprintHash)
            ->update([
                'trust_level' => 'blocked',
                'updated_at' => now(),
            ]);

        // Add to blacklist
        DB::table('blacklists')->insert([
            'id' => \Str::uuid(),
            'type' => 'fingerprint',
            'value' => $fingerprintHash,
            'reason' => $reason,
            'is_global' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
