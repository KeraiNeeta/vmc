<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DispatchAssigned extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $dispatch
     */
    public function __construct(private readonly array $dispatch) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'dispatch.assigned',
            ...$this->dispatch,
        ];
    }
}
