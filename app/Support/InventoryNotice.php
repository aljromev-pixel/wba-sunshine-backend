<?php

namespace App\Support;

use Illuminate\Notifications\Notification;

class InventoryNotice extends Notification
{
    /** @param array<string, int|string|null> $details */
    public function __construct(public array $details) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string|null> */
    public function toDatabase(object $notifiable): array
    {
        return $this->details;
    }
}
