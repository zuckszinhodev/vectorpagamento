<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'VerificaÃ§Ã£o KYC - Ajustes necessÃ¡rios - Vector Pagamentos',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyc-rejected',
            with: [
                'userName' => $this->user->name,
                'reason' => $this->reason,
                'resubmitUrl' => config('app.frontend_url', env('FRONTEND_URL', 'https://app.vectorpagamentos.com')) . '/kyc',
            ],
        );
    }
}
