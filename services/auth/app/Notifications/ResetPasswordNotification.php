<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends the password-reset link to the user's email.
 *
 * The reset URL points to the frontend app (APP_FRONTEND_URL), not the API,
 * so the user lands on the Vue reset-password page with token and email
 * pre-filled as query parameters.
 *
 * Token expiry is controlled by auth.passwords.users.expire (default: 60 min).
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(private readonly string $token)
    {
    }

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/reset-password'
            .'?token='.urlencode($this->token)
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        $expireMinutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject(__('Reset your SaintAugustin password'))
            ->greeting(__('Hello,'))
            ->line(__('You are receiving this email because a password reset was requested for your account.'))
            ->action(__('Reset Password'), $resetUrl)
            ->line(__('This link will expire in :count minutes.', ['count' => $expireMinutes]))
            ->line(__('If you did not request a password reset, no further action is required.'))
            ->salutation(__('The SaintAugustin team'));
    }
}
