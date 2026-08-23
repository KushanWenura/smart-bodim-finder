<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $message, public ?string $link = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'message' => $this->message, 'link' => $this->link && str_starts_with($this->link, '/') ? $this->link : null];
    }
}
