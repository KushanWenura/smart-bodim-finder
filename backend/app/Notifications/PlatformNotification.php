<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $message, public ?string $link = null) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->notification_email_enabled ?? true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->greeting('Hello '.$notifiable->name.',')->line($this->message);
        if ($this->link && str_starts_with($this->link, '/')) {
            $mail->action('Open BodimBuddy.lk', rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://127.0.0.1:5173')), '/').$this->link);
        }

        return $mail->line('Never send money before viewing a property and verifying the owner.');
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'message' => $this->message, 'link' => $this->link && str_starts_with($this->link, '/') ? $this->link : null];
    }
}
