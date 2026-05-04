<?php

namespace App\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        public string $accountId,
        public string $reportType,
        public array $filters,
        public string $format = 'csv'
    ) {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        Log::info("Generating report", [
            'account_id' => $this->accountId,
            'type' => $this->reportType,
            'format' => $this->format,
        ]);

        try {
            $data = $this->fetchReportData();
            $filePath = $this->generateFile($data);

            Log::info("Report generated successfully", [
                'account_id' => $this->accountId,
                'type' => $this->reportType,
                'file' => $filePath,
                'rows' => count($data),
            ]);

            // Send notification to user
            $this->notifyUser($filePath);

        } catch (\Exception $e) {
            Log::error("Failed to generate report", [
                'account_id' => $this->accountId,
                'type' => $this->reportType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function fetchReportData(): array
    {
        $query = match ($this->reportType) {
            'charges' => $this->getChargesQuery(),
            'transactions' => $this->getTransactionsQuery(),
            'splits' => $this->getSplitsQuery(),
            default => throw new \Exception("Unknown report type: {$this->reportType}"),
        };

        return $query->get()->toArray();
    }

    private function getChargesQuery()
    {
        $query = DB::table('charges')
            ->where('account_id', $this->accountId);

        if (isset($this->filters['start_date'])) {
            $query->where('created_at', '>=', $this->filters['start_date']);
        }

        if (isset($this->filters['end_date'])) {
            $query->where('created_at', '<=', $this->filters['end_date']);
        }

        if (isset($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    private function getTransactionsQuery()
    {
        $query = DB::table('transactions')
            ->join('charges', 'transactions.charge_id', '=', 'charges.id')
            ->where('charges.account_id', $this->accountId);

        if (isset($this->filters['start_date'])) {
            $query->where('transactions.created_at', '>=', $this->filters['start_date']);
        }

        if (isset($this->filters['end_date'])) {
            $query->where('transactions.created_at', '<=', $this->filters['end_date']);
        }

        return $query->orderBy('transactions.created_at', 'desc');
    }

    private function getSplitsQuery()
    {
        $query = DB::table('splits')
            ->join('charges', 'splits.charge_id', '=', 'charges.id')
            ->where('charges.account_id', $this->accountId);

        if (isset($this->filters['start_date'])) {
            $query->where('splits.created_at', '>=', $this->filters['start_date']);
        }

        if (isset($this->filters['end_date'])) {
            $query->where('splits.created_at', '<=', $this->filters['end_date']);
        }

        return $query->orderBy('splits.created_at', 'desc');
    }

    private function generateFile(array $data): string
    {
        $filename = sprintf(
            'reports/%s/%s_%s.%s',
            $this->accountId,
            $this->reportType,
            now()->format('Y-m-d_His'),
            $this->format
        );

        if ($this->format === 'csv') {
            $csv = $this->arrayToCsv($data);
            Storage::disk('local')->put($filename, $csv);
        } elseif ($this->format === 'json') {
            Storage::disk('local')->put($filename, json_encode($data, JSON_PRETTY_PRINT));
        }

        return $filename;
    }

    private function arrayToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Write header
        fputcsv($output, array_keys((array) $data[0]));

        // Write data
        foreach ($data as $row) {
            fputcsv($output, (array) $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function notifyUser(string $filePath): void
    {
        try {
            $user = \App\Models\User::whereHas('accounts', function ($q) {
                $q->where('accounts.id', $this->accountId);
            })->first();

            if (!$user) {
                // Fallback: find user with matching account_id
                $user = \App\Models\User::where('account_id', $this->accountId)->first();
            }

            if ($user) {
                $downloadUrl = config('app.url') . '/api/v1/reports/download/' . basename($filePath);
                $reportTypeNames = [
                    'charges' => 'Cobranças',
                    'transactions' => 'Transações',
                    'splits' => 'Splits',
                    'refunds' => 'Reembolsos',
                    'affiliates' => 'Afiliados',
                ];

                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->queue(new \App\Mail\ReportReadyMail(
                        $user->name,
                        $reportTypeNames[$this->reportType] ?? $this->reportType,
                        $downloadUrl
                    ));

                // Also create an in-app notification
                \App\Models\Notification::create([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'type' => 'report_ready',
                    'title' => 'Relatório pronto',
                    'message' => 'Seu relatório de ' . ($reportTypeNames[$this->reportType] ?? $this->reportType) . ' está pronto para download.',
                    'data' => json_encode(['download_url' => $downloadUrl, 'file' => $filePath]),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify user about report', ['error' => $e->getMessage()]);
        }
    }
}
