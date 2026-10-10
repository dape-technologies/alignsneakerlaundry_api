<?php

namespace App\Mail;

use App\Models\CleaningSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PickupReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CleaningSession $cleaningSession) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ready for collection — {$this->cleaningSession->shoe->display_name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.pickup-ready');
    }
}
