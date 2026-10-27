<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AcquirerAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🚀 Nova adquirente disponível: Stone | Pagar.me - GorillaPay',
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'https://gorillapay.tech'), '/');

        return new Content(
            view: 'emails.announcement-stone-pagarme',
            with: [
                'bannerUrl' => $frontendUrl . '/img/email/stone-pagarme.jpg',
                'supportUrl' => $frontendUrl . '/support',
            ],
        );
    }
}
