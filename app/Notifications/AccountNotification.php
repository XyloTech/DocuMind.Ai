<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The optional email channel for opted-in, email-capable notification types.
 * Queued so a slow mailer never delays the request that raised the event.
 */
class AccountNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $link = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->title);

        if ($this->body !== null && $this->body !== '') {
            $mail->line($this->body);
        }

        if ($this->link !== null && $this->link !== '') {
            $mail->action('Open in '.config('app.name'), $this->link);
        }

        return $mail->line('You receive this because email alerts are enabled for '.$this->type->category()->label().' in your notification preferences.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }
}
