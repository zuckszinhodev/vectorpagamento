<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Parabens, sua conta foi aprovada - GorillaPay',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyc-approved',
            with: [
                'userName' => $this->user->name,
                'dashboardUrl' => config('app.frontend_url', env('FRONTEND_URL', 'https://gorillapay.site')) . '/dashboard',
            ],
        );
    }
}
