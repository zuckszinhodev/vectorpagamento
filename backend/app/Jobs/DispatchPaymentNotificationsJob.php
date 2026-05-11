<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Charge;
use App\Services\FacebookConversionsApiService;
use App\Services\MessagingService;
use App\Services\N8nWebhookService;
use App\Services\UtmifyService;
use App\Services\XtrackyService;
use App\Services\WebhookDispatchService;
use App\Mail\PaymentApprovedCustomerMail;
use App\Mail\PaymentApprovedMerchantMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Dispatches all external notifications for a payment event.
 *
 * Moved from synchronous calls inside PaymentService webhook handler
 * to an async job so that webhook responses return instantly (<200ms).
 *
 * Covers: N8n events, account webhooks, UTMify, email notifications.
 */
class DispatchPaymentNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10; // seconds between retries

    public function __construct(
        private readonly string $chargeId,
        private readonly string $event,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        N8nWebhookService $n8nService,
        WebhookDispatchService $webhookDispatch,
        UtmifyService $utmifyService,
        FacebookConversionsApiService $facebookService,
        XtrackyService $xtrackyService,
        MessagingService $messagingService,
    ): void {
        $charge = Charge::find($this->chargeId);
        if (!$charge) {
            Log::warning('DispatchPaymentNotificationsJob: charge not found', ['charge_id' => $this->chargeId]);
            return;
        }

        Log::info('DispatchPaymentNotificationsJob: processing', [
            'charge_id' => $charge->id,
            'event'     => $this->event,
        ]);

        // 1. N8n
        $this->safeDispatch(fn () => $n8nService->dispatch($this->event, $this->buildN8nPayload($charge), $charge->account_id), 'n8n');

        // 2. Account webhooks
        $this->safeDispatch(fn () => $webhookDispatch->dispatchPaymentEventSync($this->event, $charge), 'account_webhook');
        if ($this->event === 'payment.approved') {
            $this->safeDispatch(fn () => $webhookDispatch->dispatchPaymentEventSync('charge.paid', $charge), 'account_webhook_charge_paid');
        } elseif ($this->event === 'payment.refunded') {
            $this->safeDispatch(fn () => $webhookDispatch->dispatchPaymentEventSync('charge.refunded', $charge), 'account_webhook_charge_refunded');
        }

        // 3. UTMify
        $this->safeDispatch(fn () => $utmifyService->dispatchSync($charge), 'utmify');

        // 4. Facebook Conversions API
        $this->safeDispatch(fn () => $facebookService->dispatchSync($charge), 'facebook_capi');

        // 5. XTracky
        $this->safeDispatch(fn () => $xtrackyService->dispatchSync($charge), 'xtracky');

        // 6. WhatsApp/SMS Messaging (Evolution API, Z-API, Twilio, etc.)
        $this->safeDispatch(fn () => $this->dispatchMessaging($charge, $messagingService), 'messaging');

        // 7. Emails (only for approved)
        if ($this->event === 'payment.approved') {
            $this->sendPaymentApprovedEmails($charge);
        }
    }

    private function safeDispatch(callable $fn, string $label): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("DispatchPaymentNotificationsJob: {$label} failed", [
                'charge_id' => $this->chargeId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function buildN8nPayload(Charge $charge): array
    {
        return [
            'charge_id'      => $charge->id,
            'amount'         => $charge->amount,
            'currency'       => $charge->currency ?? 'BRL',
            'status'         => $charge->status,
            'payment_method' => $charge->payment_method,
            'customer'       => [
                'name'     => $charge->customer['name'] ?? null,
                'email'    => $charge->customer['email'] ?? $charge->customer_email,
                'document' => $charge->customer['document'] ?? null,
            ],
            'product' => [
                'id'   => $charge->product_id,
                'name' => $charge->product?->name ?? $charge->description,
            ],
            'paid_at'    => $charge->paid_at?->toIso8601String(),
            'created_at' => $charge->created_at?->toIso8601String(),
        ];
    }

    /**
     * Dispatch WhatsApp/SMS messages via configured providers (Evolution API, Z-API, Twilio, etc.)
     */
    private function dispatchMessaging(Charge $charge, MessagingService $messagingService): void
    {
        $customer = $charge->customer ?? [];
        $recipientPhone = $customer['phone'] ?? $customer['telefone'] ?? null;

        if (!$recipientPhone) {
            Log::debug('DispatchPaymentNotificationsJob: no customer phone for messaging', [
                'charge_id' => $charge->id,
            ]);
            return;
        }

        $recipientName = $customer['name'] ?? $customer['nome'] ?? null;

        $variables = [
            'customer_name'  => $recipientName ?? 'Cliente',
            'customer_phone' => $recipientPhone,
            'customer_email' => $customer['email'] ?? $charge->customer_email ?? '',
            'amount'         => number_format(($charge->amount ?? 0) / 100, 2, ',', '.'),
            'currency'       => $charge->currency ?? 'BRL',
            'charge_id'      => $charge->id,
            'product_name'   => $charge->product?->name ?? $charge->description ?? '',
            'payment_method' => $charge->payment_method ?? '',
            'status'         => $charge->status ?? '',
            'pix_code'       => $charge->pix_qr_code ?? $charge->pix_copy_paste ?? '',
            'boleto_url'     => $charge->boleto_url ?? '',
            'paid_at'        => $charge->paid_at?->format('d/m/Y H:i') ?? '',
            'created_at'     => $charge->created_at?->format('d/m/Y H:i') ?? '',
        ];

        // Map internal events to messaging trigger events
        $messagingEvent = match ($this->event) {
            'payment.approved'  => 'payment.approved',
            'payment.rejected', 'payment.refused' => 'payment.refused',
            'payment.expired'   => 'payment.expired',
            'payment.refunded'  => 'refund.completed',
            'charge.created'    => 'charge.created',
            'pix.generated'     => 'pix.generated',
            'boleto.generated'  => 'boleto.generated',
            'chargeback.created' => 'chargeback.created',
            'checkout.abandoned' => 'checkout.abandoned',
            'pix.expired'       => 'pix.expired',
            'boleto.expired'    => 'boleto.expired',
            default             => $this->event,
        };

        $logs = $messagingService->sendForEvent(
            $charge->account_id,
            $messagingEvent,
            $variables,
            $recipientPhone,
            $recipientName,
            $charge->id,
        );

        Log::info('DispatchPaymentNotificationsJob: messaging dispatched', [
            'charge_id'  => $charge->id,
            'event'      => $messagingEvent,
            'sent_count' => count($logs),
        ]);
    }

    private function sendPaymentApprovedEmails(Charge $charge): void
    {
        try {
            // Merchant email
            $account = $charge->account ?? \App\Models\Account::find($charge->account_id);
            if ($account) {
                $merchantUsers = \App\Models\User::where('account_id', $account->id)->get();
                foreach ($merchantUsers as $user) {
                    if ($user->email) {
                        Mail::to($user->email)->queue(new PaymentApprovedMerchantMail($charge, $user->name ?? $account->name));
                    }
                }
            }

            // Customer email
            $customerEmail = $charge->customer['email'] ?? null;
            if ($customerEmail) {
                Mail::to($customerEmail)->queue(new PaymentApprovedCustomerMail($charge));
            }
        } catch (\Throwable $e) {
            Log::warning('DispatchPaymentNotificationsJob: email failed', [
                'charge_id' => $charge->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
