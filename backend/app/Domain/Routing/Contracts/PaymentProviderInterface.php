<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\ProbeResult;
use App\Domain\Routing\ValueObjects\ProviderRequest;
use App\Domain\Routing\ValueObjects\ProviderResponse;

/**
 * The contract every acquirer module implements.
 *
 * This is the seam that makes acquirers pluggable: the router, the executor
 * and the dashboard talk only to this interface, so adding an acquirer means
 * writing one class and registering it — no `match` in PaymentService, no new
 * branch in a factory, no change to the routing core.
 *
 * Implementations MUST NOT throw for business failures (a declined card, a
 * rejected amount): return an unsuccessful {@see ProviderResponse} with the
 * right {@see \App\Domain\Routing\Enums\FailureReason} so the router can tell
 * "this acquirer is broken" from "this transaction was refused".
 */
interface PaymentProviderInterface
{
    /**
     * Stable identifier, matching `acquirer_configs.acquirer_key`.
     */
    public function key(): string;

    public function displayName(): string;

    /**
     * @return array<int, PaymentMethod>
     */
    public function supports(): array;

    public function supportsMethod(PaymentMethod $method): bool;

    /**
     * Create a charge (PIX QR code, boleto or card authorisation).
     */
    public function charge(ProviderRequest $request): ProviderResponse;

    /**
     * Send money out (PIX transfer / withdrawal).
     */
    public function payout(ProviderRequest $request): ProviderResponse;

    /**
     * Refund a previously approved charge.
     */
    public function refund(ProviderRequest $request, ?int $amountCents = null): ProviderResponse;

    /**
     * Fetch the current status of a transaction at the acquirer.
     */
    public function fetchStatus(string $providerTransactionId): ProviderResponse;

    /**
     * Cheap, side-effect-free connectivity check.
     */
    public function probe(): ProbeResult;
}
