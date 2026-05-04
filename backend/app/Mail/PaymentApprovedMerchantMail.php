<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Charge;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentApprovedMerchantMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Charge $charge,
        public string $merchantName,
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format($this->charge->amount / 100, 2, ',', '.');

        return new Envelope(
            subject: "Nova venda aprovada - R$ {$amount}",
        );
    }

    public function content(): Content
    {
        $customer = $this->charge->customer ?? [];

        return new Content(
            view: 'emails.payment-approved-merchant',
            with: [
                'merchantName' => $this->merchantName,
                'chargeId' => $this->charge->id,
                'amount' => number_format($this->charge->amount / 100, 2, ',', '.'),
                'paymentMethod' => $this->formatPaymentMethod($this->charge->payment_method),
                'customerName' => $customer['name'] ?? 'N/A',
                'customerEmail' => $customer['email'] ?? 'N/A',
                'productDescription' => $this->charge->description ?? 'N/A',
                'paidAt' => $this->charge->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                'dashboardUrl' => config('app.frontend_url', env('FRONTEND_URL', 'https://app.wazerpay.com.br')) . '/dashboard',
            ],
        );
    }

    private function formatPaymentMethod(?string $method): string
    {
        return match ($method) {
            'pix' => 'PIX',
            'credit_card' => 'Cartão de Crédito',
            'boleto' => 'Boleto',
            default => $method ?? 'N/A',
        };
    }
}
