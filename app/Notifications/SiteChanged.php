<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Her Terms or Privacy page changed in a way that matters: "My Terms have changed", with the line she wrote about it.
 * Reaches the site only.
 */
class SiteChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $page  'terms' or 'privacy'
     */
    public function __construct(public string $page, public string $line) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, page: string, line: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return ['kind' => 'site_change', 'page' => $this->page, 'line' => $this->line];
    }
}
