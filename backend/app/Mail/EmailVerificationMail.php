<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;

    public function __construct(
        public User $user,
        string $token,
    ) {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://app.wazerpay.com.br'));
        $this->verificationUrl = "{$frontendUrl}/verify-email?token={$token}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifique seu e-mail — GatePro',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            with: [
                'userName' => $this->user->name,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }
}
