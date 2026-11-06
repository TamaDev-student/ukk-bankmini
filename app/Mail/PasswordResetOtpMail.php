<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    public string $userName;

    public string $purpose;

    public function __construct(string $otp, string $userName, string $purpose = 'password_reset')
    {
        $this->otp = $otp;
        $this->userName = $userName;
        $this->purpose = $purpose;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'pin_reset'
                ? 'Kode OTP Reset PIN Transaksi — E-Teller Bank Mini Sekolah'
                : 'Kode OTP Pemulihan Password — E-Teller Bank Mini Sekolah',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset_otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
