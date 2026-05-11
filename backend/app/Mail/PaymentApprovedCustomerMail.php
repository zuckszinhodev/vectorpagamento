<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Charge;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentApprovedCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Charge $charge,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pagamento confirmado - Vector Pagamentos',
        );
    }

    public function content(): Content
    {
        $customer = $this->charge->customer ?? [];

        return new Content(
            view: 'emails.payment-approved-customer',
            with: [
                'customerName' => $customer['name'] ?? 'Cliente',
                'chargeId' => $this->charge->id,
                'amount' => number_format($this->charge->amount / 100, 2, ',', '.'),
                'paymentMethod' => $this->formatPaymentMethod($this->charge->payment_method),
                'productDescription' => $this->charge->description ?? 'Compra na Vector Pagamentos',
                'paidAt' => $this->charge->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
            ],
        );
    }

    private function formatPaymentMethod(?string $method): string
    {
        return match ($method) {
            'pix' => 'PIX',
            'credit_card' => 'CartÃ£o de CrÃ©dito',
            'boleto' => 'Boleto',
            default => $method ?? 'N/A',
        };
    }
}
