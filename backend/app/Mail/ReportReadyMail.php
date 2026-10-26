<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $reportType,
        public string $downloadUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seu relatório está pronto - GorillaPay',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-ready',
            with: [
                'userName' => $this->userName,
                'reportType' => $this->reportType,
                'downloadUrl' => $this->downloadUrl,
            ],
        );
    }
}
