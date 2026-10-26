<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Redefinir sua senha - GorillaPay',
        );
    }

    public function content(): Content
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://gorillapay.site'));

        return new Content(
            view: 'emails.password-reset',
            with: [
                'userName' => $this->user->name,
                'resetUrl' => $frontendUrl . '/reset-password?token=' . $this->resetToken,
            ],
        );
    }
}
