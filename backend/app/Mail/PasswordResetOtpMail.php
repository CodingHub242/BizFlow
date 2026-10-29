<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $userName,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('Your XBeez Password Reset Code')
            ->view('emails.password-reset-otp');
    }
}
