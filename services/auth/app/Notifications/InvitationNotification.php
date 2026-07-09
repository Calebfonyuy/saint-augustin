<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends an invitation email with a one-time registration link.
 *
 * The link points to the Vue frontend: APP_FRONTEND_URL/register?token={token}
 * where the frontend pre-fills the email and sends it back with the form.
 *
 * Expiry is set by INVITATION_EXPIRE_HOURS (default 48).
 */
class InvitationNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $inviterName,
        private readonly int $expireHours,
    ) {
    }

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The invitation flow uses an `AnonymousNotifiable` (no User row exists
        // yet at invitation time), which exposes `routeNotificationFor()` but
        // *not* `routeNotificationForMail()`. The latter is a convention on
        // models using the `Notifiable` trait; calling it on an anonymous
        // notifiable throws "undefined method". `routeNotificationFor('mail')`
        // works on both, so we use it unconditionally.
        $emailRoute = $notifiable->routeNotificationFor('mail');
        $email = is_array($emailRoute) ? (string) ($emailRoute[0] ?? '') : (string) $emailRoute;

        $registerUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/register'
            .'?token='.urlencode($this->token)
            .'&email='.urlencode($email);

        return (new MailMessage())
            ->subject(__('You have been invited to SaintAugustin'))
            ->greeting(__('Hello,'))
            ->line(__(':inviter has invited you to join SaintAugustin, a worship management platform.', [
                'inviter' => $this->inviterName,
            ]))
            ->action(__('Accept Invitation'), $registerUrl)
            ->line(__('This invitation will expire in :count hours.', ['count' => $this->expireHours]))
            ->line(__('If you were not expecting an invitation, you can ignore this email.'))
            ->salutation(__('The SaintAugustin team'));
    }
}
