<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $otp;

    public int $ttlMinutes;

    public function __construct(string $otp, int $ttlMinutes)
    {
        $this->otp = $otp;
        $this->ttlMinutes = $ttlMinutes;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SMSGang Email Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification-otp',
            with: [
                'otp' => $this->otp,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
