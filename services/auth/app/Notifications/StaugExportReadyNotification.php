<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the admin who requested a full-library export a time-limited
 * download link (SRS FR-DF-2). The link is a presigned MinIO URL that expires
 * with the object.
 */
class StaugExportReadyNotification extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly int $expiresInHours,
    ) {
    }

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('Your SaintAugustin library export is ready'))
            ->greeting(__('Hello,'))
            ->line(__('The full-library export you requested is ready to download.'))
            ->action(__('Download export'), $this->url)
            ->line(__('This link will expire in :count hours.', ['count' => $this->expiresInHours]))
            ->line(__('If you did not request this export, you can ignore this email.'))
            ->salutation(__('The SaintAugustin team'));
    }
}
