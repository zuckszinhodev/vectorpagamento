<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Webhooks;

use Application\Payment\Services\PaymentService;
use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeWebhookHandler
{
    public function __construct(
        private ChargeRepositoryInterface $chargeRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private PaymentService $paymentService
    ) {}

    public function handle(Request $request): array
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('acquirers.stripe.webhook_secret');

        // Verify webhook signature
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'error', 'message' => 'Invalid signature'];
        }

        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        return match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
            'charge.succeeded' => $this->handleChargeSucceeded($event->data->object),
            'charge.failed' => $this->handleChargeFailed($event->data->object),
            'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            'charge.dispute.created' => $this->handleDisputeCreated($event->data->object),
            default => ['status' => 'ignored', 'type' => $event->type],
        };
    }

    private function handlePaymentSucceeded(object $paymentIntent): array
    {
        $chargeId = $paymentIntent->metadata->charge_id ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($paymentIntent->id);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::APPROVED,
                ['webhook_event' => 'payment_intent.succeeded']
            );
            $this->transactionRepository->save($transaction);
        }

        // Mark charge as paid
        if ($charge->getStatus() !== 'paid') {
            $charge->markAsPaid(
                transactionId: $paymentIntent->id,
                paidAt: now()
            );
            $this->chargeRepository->save($charge);
        }

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentFailed(object $paymentIntent): array
    {
        $chargeId = $paymentIntent->metadata->charge_id ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($paymentIntent->id);

        if ($transaction) {
            $errorMessage = $paymentIntent->last_payment_error->message ?? 'Payment failed';
            $errorCode = $paymentIntent->last_payment_error->code ?? 'unknown';

            $transaction->updateStatus(
                TransactionStatus::DECLINED,
                [
                    'webhook_event' => 'payment_intent.payment_failed',
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargeSucceeded(object $charge): array
    {
        $chargeId = $charge->metadata->charge_id ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        Log::info('Stripe charge succeeded', [
            'stripe_charge_id' => $charge->id,
            'charge_id' => $chargeId,
            'amount' => $charge->amount,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargeFailed(object $charge): array
    {
        $chargeId = $charge->metadata->charge_id ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        Log::warning('Stripe charge failed', [
            'stripe_charge_id' => $charge->id,
            'charge_id' => $chargeId,
            'failure_code' => $charge->failure_code,
            'failure_message' => $charge->failure_message,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargeRefunded(object $charge): array
    {
        $chargeId = $charge->metadata->charge_id ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $chargeEntity = $this->chargeRepository->findById($chargeId);

        if (!$chargeEntity) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Calculate refunded amount
        $refundedAmount = $charge->amount_refunded;
        $isFullRefund = $charge->refunded === true;

        if ($isFullRefund) {
            $chargeEntity->markAsRefunded();
        } else {
            $chargeEntity->markAsPartiallyRefunded($refundedAmount);
        }

        $this->chargeRepository->save($chargeEntity);

        Log::info('Stripe charge refunded', [
            'stripe_charge_id' => $charge->id,
            'charge_id' => $chargeId,
            'refunded_amount' => $refundedAmount,
            'full_refund' => $isFullRefund,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleDisputeCreated(object $dispute): array
    {
        $chargeId = $dispute->payment_intent ?? null;

        Log::warning('Stripe dispute created', [
            'dispute_id' => $dispute->id,
            'charge_id' => $chargeId,
            'amount' => $dispute->amount,
            'reason' => $dispute->reason,
        ]);

        // Find associated charge and mark as disputed
        if ($chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($chargeId);

            if ($transaction) {
                $charge = $this->chargeRepository->findById($transaction->getChargeId()->toString());

                if ($charge) {
                    $charge->markAsDisputed([
                        'dispute_id' => $dispute->id,
                        'reason' => $dispute->reason,
                        'amount' => $dispute->amount,
                    ]);
                    $this->chargeRepository->save($charge);
                }
            }
        }

        return ['status' => 'processed', 'dispute_id' => $dispute->id];
    }
}
