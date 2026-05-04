<?php

namespace App\Domain\Fraud\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FraudDetectionService
{
    public function __construct(
        private DeviceFingerprintService $deviceService,
        private RiskScoringService $riskService
    ) {}

    /**
     * Analyze a transaction for fraud
     */
    public function analyzeTransaction(
        string $chargeId,
        string $accountId,
        array $transactionData
    ): array {
        Log::info("Analyzing transaction for fraud", ['charge_id' => $chargeId]);

        $riskFactors = [];
        $totalScore = 0;

        // 1. Device Fingerprinting
        if (!empty($transactionData['device_fingerprint'])) {
            $deviceAnalysis = $this->deviceService->analyzeDevice(
                $transactionData['device_fingerprint'],
                $transactionData['customer_email'] ?? null
            );

            $riskFactors['device'] = $deviceAnalysis;
            $totalScore += $deviceAnalysis['score'];
        }

        // 2. Blacklist Check
        $blacklistCheck = $this->checkBlacklists($accountId, $transactionData);
        $riskFactors['blacklist'] = $blacklistCheck;
        $totalScore += $blacklistCheck['score'];

        // 3. Velocity Check
        $velocityCheck = $this->checkVelocity($transactionData);
        $riskFactors['velocity'] = $velocityCheck;
        $totalScore += $velocityCheck['score'];

        // 4. Amount Analysis
        $amountCheck = $this->checkAmount($transactionData);
        $riskFactors['amount'] = $amountCheck;
        $totalScore += $amountCheck['score'];

        // 5. Time Pattern Analysis
        $timeCheck = $this->checkTimePatterns($transactionData);
        $riskFactors['time_pattern'] = $timeCheck;
        $totalScore += $timeCheck['score'];

        // 6. BIN Analysis
        if (!empty($transactionData['card_bin'])) {
            $binCheck = $this->checkBIN($transactionData['card_bin']);
            $riskFactors['bin'] = $binCheck;
            $totalScore += $binCheck['score'];
        }

        // 7. Geolocation Anomaly Detection (impossible travel)
        $geoCheck = $this->checkGeolocationAnomaly($transactionData);
        $riskFactors['geolocation'] = $geoCheck;
        $totalScore += $geoCheck['score'];

        // 8. Card Testing / Micro-transaction Detection
        $cardTestCheck = $this->checkCardTesting($transactionData);
        $riskFactors['card_testing'] = $cardTestCheck;
        $totalScore += $cardTestCheck['score'];

        // 9. Email Pattern Analysis (disposable emails, suspicious patterns)
        $emailCheck = $this->checkEmailPattern($transactionData);
        $riskFactors['email_pattern'] = $emailCheck;
        $totalScore += $emailCheck['score'];

        // Calculate final risk level
        $riskLevel = $this->calculateRiskLevel($totalScore);
        $recommendation = $this->getRecommendation($totalScore, $riskFactors);

        // Save risk score
        $riskScoreId = DB::table('risk_scores')->insertGetId([
            'id' => Str::uuid(),
            'charge_id' => $chargeId,
            'total_score' => $totalScore,
            'score_breakdown' => json_encode($riskFactors),
            'risk_level' => $riskLevel,
            'recommendation' => $recommendation,
            'triggered_rules' => json_encode($this->getTriggeredRules($riskFactors)),
            'device_fingerprint' => json_encode($transactionData['device_fingerprint'] ?? null),
            'ip_address' => $transactionData['ip_address'] ?? null,
            'geolocation' => json_encode($transactionData['geolocation'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update charge with risk info
        DB::table('charges')->where('id', $chargeId)->update([
            'risk_score' => $totalScore,
            'risk_level' => $riskLevel,
            'requires_review' => $recommendation === 'review',
            'device_fingerprint' => $transactionData['device_fingerprint'] ?? null,
        ]);

        return [
            'risk_score' => $totalScore,
            'risk_level' => $riskLevel,
            'recommendation' => $recommendation,
            'factors' => $riskFactors,
            'should_block' => $recommendation === 'decline',
            'requires_review' => $recommendation === 'review',
        ];
    }

    private function checkBlacklists(string $accountId, array $data): array
    {
        $score = 0;
        $matches = [];

        $checks = [
            ['type' => 'email', 'value' => $data['customer_email'] ?? null],
            ['type' => 'document', 'value' => $data['document'] ?? null],
            ['type' => 'ip', 'value' => $data['ip_address'] ?? null],
            ['type' => 'bin', 'value' => $data['card_bin'] ?? null],
            ['type' => 'fingerprint', 'value' => $data['device_fingerprint'] ?? null],
        ];

        foreach ($checks as $check) {
            if (!$check['value']) continue;

            $found = DB::table('blacklists')
                ->where('type', $check['type'])
                ->where('value', $check['value'])
                ->where(function ($q) use ($accountId) {
                    $q->where('account_id', $accountId)
                      ->orWhere('is_global', true);
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($found) {
                $score += 50; // High penalty for blacklist match
                $matches[] = $check['type'];
            }
        }

        return [
            'score' => min($score, 100),
            'matches' => $matches,
            'is_blacklisted' => !empty($matches),
        ];
    }

    private function checkVelocity(array $data): array
    {
        $score = 0;
        $violations = [];

        // Check email velocity (max 5 transactions per hour)
        if (!empty($data['customer_email'])) {
            $emailCount = DB::table('charges')
                ->where('customer_email', $data['customer_email'])
                ->where('created_at', '>', now()->subHour())
                ->count();

            if ($emailCount > 5) {
                $score += 30;
                $violations[] = "email_velocity:{$emailCount}";
            }
        }

        // Check IP velocity
        if (!empty($data['ip_address'])) {
            $ipCount = DB::table('charges')
                ->where('ip_address', $data['ip_address'])
                ->where('created_at', '>', now()->subHour())
                ->count();

            if ($ipCount > 10) {
                $score += 25;
                $violations[] = "ip_velocity:{$ipCount}";
            }
        }

        // Check card velocity
        if (!empty($data['card_bin'])) {
            $cardCount = DB::table('charges')
                ->where('card_bin', $data['card_bin'])
                ->where('created_at', '>', now()->subMinutes(15))
                ->count();

            if ($cardCount > 3) {
                $score += 35;
                $violations[] = "card_velocity:{$cardCount}";
            }
        }

        return [
            'score' => min($score, 100),
            'violations' => $violations,
            'has_violations' => !empty($violations),
        ];
    }

    private function checkAmount(array $data): array
    {
        $score = 0;
        $flags = [];
        $amount = $data['amount'] ?? 0;

        // Very high amount
        if ($amount > 5000) {
            $score += 20;
            $flags[] = 'high_amount';
        }

        // Suspiciously round number
        if ($amount > 100 && $amount == floor($amount)) {
            $score += 5;
            $flags[] = 'round_amount';
        }

        // Check if amount is unusual for this customer
        if (!empty($data['customer_email'])) {
            $avgAmount = DB::table('charges')
                ->where('customer_email', $data['customer_email'])
                ->where('status', 'approved')
                ->avg('amount');

            if ($avgAmount && $amount > ($avgAmount * 3)) {
                $score += 15;
                $flags[] = 'unusual_amount';
            }
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
            'amount' => $amount,
        ];
    }

    private function checkTimePatterns(array $data): array
    {
        $score = 0;
        $flags = [];
        $hour = now()->hour;

        // Late night transactions (2am - 5am) are more suspicious
        if ($hour >= 2 && $hour <= 5) {
            $score += 10;
            $flags[] = 'late_night';
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'hour' => $hour,
        ];
    }

    private function checkBIN(string $bin): array
    {
        $score = 0;
        $info = [];

        // Check if BIN is in blacklist
        $isBlacklisted = DB::table('blacklists')
            ->where('type', 'bin')
            ->where('value', $bin)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($isBlacklisted) {
            $score += 40;
            $info['blacklisted'] = true;
        }

        return [
            'score' => $score,
            'bin' => $bin,
            'info' => $info,
        ];
    }

    /**
     * Detect impossible travel — same customer/card from distant locations in short time
     */
    private function checkGeolocationAnomaly(array $data): array
    {
        $score = 0;
        $flags = [];

        $ip = $data['ip_address'] ?? null;
        $customerEmail = $data['customer_email'] ?? null;

        if (!$ip || !$customerEmail) {
            return ['score' => 0, 'flags' => [], 'has_anomaly' => false];
        }

        // Get last transaction from this customer within 2 hours
        $lastTransaction = DB::table('charges')
            ->where('customer_email', $customerEmail)
            ->where('created_at', '>', now()->subHours(2))
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', $ip)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastTransaction) {
            // Different IP within 2 hours — flag as potential anomaly
            $timeDiffMinutes = now()->diffInMinutes($lastTransaction->created_at);

            if ($timeDiffMinutes < 5) {
                // Same customer, different IP, under 5 minutes = very suspicious
                $score += 35;
                $flags[] = 'impossible_travel_critical';
            } elseif ($timeDiffMinutes < 30) {
                $score += 20;
                $flags[] = 'impossible_travel_warning';
            } else {
                $score += 5;
                $flags[] = 'ip_change_noted';
            }
        }

        // Check for multiple distinct IPs in a short window
        $distinctIps = DB::table('charges')
            ->where('customer_email', $customerEmail)
            ->where('created_at', '>', now()->subHour())
            ->distinct()
            ->count('ip_address');

        if ($distinctIps >= 4) {
            $score += 30;
            $flags[] = "multiple_ips:{$distinctIps}";
        } elseif ($distinctIps >= 2) {
            $score += 10;
            $flags[] = "ip_diversity:{$distinctIps}";
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
            'has_anomaly' => !empty($flags),
        ];
    }

    /**
     * Detect card testing attacks — rapid small transactions to verify stolen cards
     */
    private function checkCardTesting(array $data): array
    {
        $score = 0;
        $flags = [];
        $amount = $data['amount'] ?? 0;
        $ip = $data['ip_address'] ?? null;

        // Micro-transaction from same IP (card testing pattern)
        if ($amount > 0 && $amount <= 200 && $ip) { // 200 cents = R$ 2.00
            $microCount = DB::table('charges')
                ->where('ip_address', $ip)
                ->where('amount', '<=', 200)
                ->where('created_at', '>', now()->subMinutes(30))
                ->count();

            if ($microCount >= 5) {
                $score += 50; // Definitive card testing
                $flags[] = "card_testing_confirmed:{$microCount}";
            } elseif ($microCount >= 2) {
                $score += 25;
                $flags[] = "card_testing_suspected:{$microCount}";
            }
        }

        // Rapid sequential small amounts (different cards, same IP)
        if ($ip) {
            $declinedCount = DB::table('charges')
                ->where('ip_address', $ip)
                ->whereIn('status', ['declined', 'failed'])
                ->where('created_at', '>', now()->subMinutes(15))
                ->count();

            if ($declinedCount >= 5) {
                $score += 45;
                $flags[] = "high_decline_rate:{$declinedCount}";
            } elseif ($declinedCount >= 2) {
                $score += 15;
                $flags[] = "elevated_decline_rate:{$declinedCount}";
            }
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
            'is_card_testing' => $score >= 40,
        ];
    }

    /**
     * Check for disposable/temporary email domains and suspicious patterns
     */
    private function checkEmailPattern(array $data): array
    {
        $score = 0;
        $flags = [];
        $email = $data['customer_email'] ?? null;

        if (!$email) {
            return ['score' => 0, 'flags' => [], 'is_suspicious' => false];
        }

        $domain = strtolower(substr($email, strpos($email, '@') + 1));

        // Known disposable email domains
        $disposableDomains = [
            'tempmail.com', 'guerrillamail.com', 'mailinator.com', 'throwaway.email',
            'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
            'dispostable.com', 'temp-mail.org', 'fakeinbox.com', 'trashmail.com',
            'maildrop.cc', 'mailnesia.com', '10minutemail.com', 'tempail.com',
            'harakirimail.com', 'binkmail.com', 'chammy.info', 'trashmail.me',
        ];

        if (in_array($domain, $disposableDomains, true)) {
            $score += 40;
            $flags[] = 'disposable_email';
        }

        // Check for excessive numbers/random patterns in local part
        $localPart = substr($email, 0, strpos($email, '@'));
        if (preg_match('/\d{6,}/', $localPart)) {
            $score += 10;
            $flags[] = 'numeric_email_pattern';
        }

        // Check for very recent email first seen
        $firstSeen = DB::table('charges')
            ->where('customer_email', $email)
            ->min('created_at');

        if (!$firstSeen) {
            $score += 5; // Brand new email
            $flags[] = 'first_time_email';
        }

        return [
            'score' => min($score, 100),
            'flags' => $flags,
            'is_suspicious' => $score >= 30,
        ];
    }

    private function calculateRiskLevel(int $score): string
    {
        return match(true) {
            $score >= 70 => 'critical',
            $score >= 50 => 'high',
            $score >= 30 => 'medium',
            default => 'low',
        };
    }

    private function getRecommendation(int $score, array $factors): string
    {
        // Auto-decline if blacklisted
        if ($factors['blacklist']['is_blacklisted'] ?? false) {
            return 'decline';
        }

        // Recommendations based on score
        return match(true) {
            $score >= 70 => 'decline',
            $score >= 40 => 'review',
            default => 'approve',
        };
    }

    private function getTriggeredRules(array $factors): array
    {
        $triggered = [];

        foreach ($factors as $category => $data) {
            if (($data['score'] ?? 0) > 0) {
                $triggered[] = [
                    'category' => $category,
                    'score' => $data['score'],
                    'details' => $data,
                ];
            }
        }

        return $triggered;
    }
}
