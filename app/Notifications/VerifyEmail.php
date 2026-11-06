<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;

class VerifyEmail extends Notification
{
    public const TTL_SECONDS = 86400;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function tokenFor(object $user): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $user->getKey(),
            'email' => $user->email,
            'exp' => now()->getTimestamp() + self::TTL_SECONDS,
        ]));
    }

    public function url(object $notifiable): string
    {
        return rtrim(config('openpaws.ui_url'), '/').'/verify-email?token='.urlencode(self::tokenFor($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your OpenPaws email')
            ->line('Welcome to OpenPaws! Please verify your email address.')
            ->action('Verify email', $this->url($notifiable))
            ->line('This link expires in 24 hours.');
    }
}
