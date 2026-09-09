<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentTrackingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $particular,
        public string $trackingNumber,
        public string $documentType,
        public string $priorityLevel,
        public string $originSender,
        public string $dueDate,
        public string $dateSent,
        public string $guestTrackUrl,
        public ?string $qrImagePath = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tagum Document Track and Trace: {$this->particular} ({$this->trackingNumber})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.document-tracking',
            text: 'mail.document-tracking-text',
        );
    }

    public function attachments(): array
    {
        // No separate attachments as requested; QR code is embedded inline in the HTML email body
        return [];
    }
}
