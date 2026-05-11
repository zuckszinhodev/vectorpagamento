<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MemberCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $memberName,
        public string $memberEmail,
        public string $tempPassword,
        public string $courseName,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Acesso liberado: {$this->courseName} - Vector Pagamentos",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member-credentials',
            with: [
                'memberName' => $this->memberName,
                'memberEmail' => $this->memberEmail,
                'tempPassword' => $this->tempPassword,
                'courseName' => $this->courseName,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
