<?php

namespace App\Domain\Fraud\Services;

class RiskScoringService
{
    /**
     * Calculate comprehensive risk score
     */
    public function calculateRiskScore(array $factors): int
    {
        $totalScore = 0;
        $weights = [
            'device' => 1.0,
            'blacklist' => 1.5,  // Higher weight
            'velocity' => 1.2,
            'amount' => 0.8,
            'time_pattern' => 0.6,
            'bin' => 1.1,
        ];

        foreach ($factors as $category => $data) {
            $score = $data['score'] ?? 0;
            $weight = $weights[$category] ?? 1.0;
            $totalScore += ($score * $weight);
        }

        // Normalize to 0-100 range
        return min(100, (int) round($totalScore));
    }

    /**
     * Get risk level description
     */
    public function getRiskDescription(string $level): string
    {
        return match($level) {
            'critical' => 'Risco Crítico - Transação altamente suspeita',
            'high' => 'Risco Alto - Requer revisão manual',
            'medium' => 'Risco Médio - Monitorar de perto',
            'low' => 'Risco Baixo - Transação normal',
            default => 'Risco Desconhecido',
        };
    }

    /**
     * Get recommended action
     */
    public function getActionRecommendation(int $score): array
    {
        if ($score >= 70) {
            return [
                'action' => 'block',
                'message' => 'Bloquear transação automaticamente',
                'color' => 'red',
            ];
        }

        if ($score >= 40) {
            return [
                'action' => 'review',
                'message' => 'Revisar manualmente antes de aprovar',
                'color' => 'yellow',
            ];
        }

        return [
            'action' => 'approve',
            'message' => 'Aprovar transação',
            'color' => 'green',
        ];
    }
}
